<?php

namespace App\Traits;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

trait FileUploadHandler
{
    /**
     * Boot the trait and hook into Eloquent model events.
     */
    public static function bootFileUploadHandler(): void
    {
        static::saving(function ($model) {
            foreach ($model->getFileFields() as $field) {
                if ($model->isDirty($field)) {
                    $value = $model->getAttribute($field);
                    $oldValue = $model->getOriginal($field);

                    if ($value instanceof UploadedFile) {
                        // Delete old file if updating and old file exists
                        if ($model->exists && $oldValue) {
                            $model->deleteFileFromDisk($oldValue, $model->getFileDisk($field));
                        }

                        // Upload new file and assign the path
                        $path = $model->uploadFileToDisk($value, $field);
                        $model->setAttribute($field, $path);
                    } elseif ($value === null && $oldValue) {
                        // Delete old file if field is cleared/set to null
                        $model->deleteFileFromDisk($oldValue, $model->getFileDisk($field));
                    }
                }
            }
        });

        static::deleting(function ($model) {
            // Check if model is using SoftDeletes and if it is not force deleting
            if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
                return;
            }

            foreach ($model->getFileFields() as $field) {
                $filePath = $model->getAttribute($field) ?: $model->getOriginal($field);
                if ($filePath) {
                    $model->deleteFileFromDisk($filePath, $model->getFileDisk($field));
                }
            }
        });
    }

    /**
     * Get the fields that handle file uploads.
     * Override this method or define $fileFields array in the model.
     *
     * @return array<int, string>
     */
    public function getFileFields(): array
    {
        return $this->fileFields ?? [];
    }

    /**
     * Get the disk to use for file uploads.
     * Can be customized per field or globally in the model.
     */
    public function getFileDisk(string $field): string
    {
        if (isset($this->fileDisks) && is_array($this->fileDisks) && isset($this->fileDisks[$field])) {
            return $this->fileDisks[$field];
        }

        return $this->fileDisk ?? config('filesystems.default', 'public');
    }

    /**
     * Get the directory to store uploaded files.
     * Can be customized per field or globally in the model.
     */
    public function getFileDirectory(string $field): string
    {
        if (isset($this->fileDirectories) && is_array($this->fileDirectories) && isset($this->fileDirectories[$field])) {
            return $this->fileDirectories[$field];
        }

        if (isset($this->fileDirectory)) {
            return $this->fileDirectory;
        }

        return $this->getTable();
    }

    /**
     * Upload a file to the disk.
     */
    public function uploadFileToDisk(UploadedFile $file, string $field): string
    {
        $directory = $this->getFileDirectory($field);
        $disk = $this->getFileDisk($field);

        return $file->store($directory, $disk);
    }

    /**
     * Delete a file from the disk.
     */
    public function deleteFileFromDisk(?string $path, ?string $disk = null): bool
    {
        if (! $path) {
            return false;
        }

        $disk = $disk ?: config('filesystems.default', 'public');

        if (Storage::disk($disk)->exists($path)) {
            return Storage::disk($disk)->delete($path);
        }

        return false;
    }
}
