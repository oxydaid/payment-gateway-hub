<?php

use App\Traits\FileUploadHandler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

// Define a test model in the global namespace or test class context
class TestModel extends Model
{
    use FileUploadHandler, SoftDeletes;

    protected $table = 'test_models';

    protected $fillable = ['logo', 'attachment'];

    public array $fileFields = ['logo', 'attachment'];

    public string $fileDisk = 'public';

    public array $fileDisks = [
        'attachment' => 'custom_disk',
    ];
}

beforeEach(function () {
    Schema::create('test_models', function (Blueprint $table) {
        $table->id();
        $table->string('logo')->nullable();
        $table->string('attachment')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });

    Storage::fake('public');
    Storage::fake('custom_disk');
});

afterEach(function () {
    Schema::dropIfExists('test_models');
});

test('it uploads files automatically on create', function () {
    $logoFile = UploadedFile::fake()->image('logo.jpg');
    $attachmentFile = UploadedFile::fake()->create('doc.pdf');

    $model = TestModel::create([
        'logo' => $logoFile,
        'attachment' => $attachmentFile,
    ]);

    expect($model->logo)->toBeString()->not->toBeEmpty();
    expect($model->attachment)->toBeString()->not->toBeEmpty();

    Storage::disk('public')->assertExists($model->logo);
    Storage::disk('custom_disk')->assertExists($model->attachment);
});

test('it deletes the old file and uploads a new one on update', function () {
    $oldLogoFile = UploadedFile::fake()->image('old_logo.jpg');
    $model = TestModel::create([
        'logo' => $oldLogoFile,
    ]);

    $oldPath = $model->logo;
    Storage::disk('public')->assertExists($oldPath);

    $newLogoFile = UploadedFile::fake()->image('new_logo.jpg');
    $model->update([
        'logo' => $newLogoFile,
    ]);

    $newPath = $model->logo;
    expect($newPath)->not->toBe($oldPath);

    Storage::disk('public')->assertMissing($oldPath);
    Storage::disk('public')->assertExists($newPath);
});

test('it deletes the file when the field is set to null', function () {
    $logoFile = UploadedFile::fake()->image('logo.jpg');
    $model = TestModel::create([
        'logo' => $logoFile,
    ]);

    $path = $model->logo;
    Storage::disk('public')->assertExists($path);

    $model->update([
        'logo' => null,
    ]);

    expect($model->logo)->toBeNull();
    Storage::disk('public')->assertMissing($path);
});

test('it keeps file on soft delete but deletes on force delete', function () {
    $logoFile = UploadedFile::fake()->image('logo.jpg');
    $model = TestModel::create([
        'logo' => $logoFile,
    ]);

    $path = $model->logo;
    Storage::disk('public')->assertExists($path);

    $model->delete();

    Storage::disk('public')->assertExists($path);

    $model->forceDelete();

    Storage::disk('public')->assertMissing($path);
});
