# Contributing to Payment Bridge 🤝

We are thrilled that you want to help improve **Payment Bridge**! 🚀

Since this project is **100% free and open-source**, contributions are the lifeblood of its growth. Please read through these guidelines to keep the codebase clean, stable, and secure.

---

## ⛔ General Rules
* **No Reselling / Commercialization**: This project is and will always be **free**. Any contribution you make will be covered under the license prohibiting commercial resell.
* **Write Tests**: Any new payment gateway driver, API route, or business logic change **must** come with corresponding tests (Pest PHP preferred).
* **Code Styling**: Run Laravel Pint before pushing changes:
  ```bash
  vendor/bin/pint
  ```

---

## 🛠️ How to Contribute

### 1. Adding a New Payment Gateway Driver
If you want to add support for a new payment gateway (e.g. Xendit, Doku, Wise):
1. Create your driver class inside `app/Services/PaymentGateway/Drivers/`.
2. Implement the `GatewayDriverInterface`.
3. Add the driver mapping in `app/Services/PaymentGateway/PaymentGatewayManager.php`.
4. Define the schema in `availableDrivers()` inside `PaymentGatewayManager` so it registers in the dynamic frontend forms automatically.

### 2. Submitting a Pull Request
1. Fork the repository and create your branch from `main`.
2. Make your changes and write features/unit tests.
3. Verify that all tests pass:
   ```bash
   php artisan test
   ```
4. Submit a Pull Request describing your changes clearly.

Thank you for making Payment Bridge better! 💚
