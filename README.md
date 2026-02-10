# Medicine Stock & Expiry Manager

A mobile-first PHP + MySQL app for medical stores to track medicine stock, expiry risk, and reports.

## Features
- Medicine management (name, brand, category, batch, manufacture/expiry, quantity, supplier)
- Expiry warnings with color coding for 30/60/90 days
- Separate expired stock list
- Stock adjustment (reduce on sale, manual correction)
- Reports for near-expiry and out-of-stock medicines

## Setup
1. Create schema/tables:
   ```bash
   mysql -u root -p < database.sql
   ```
2. Update `config.php` with your MySQL credentials.
3. Start PHP server:
   ```bash
   php -S 0.0.0.0:8000
   ```
4. Open `http://localhost:8000`

## Notes for low-end devices
- No frontend frameworks or heavy JS
- Single-page server-rendered UI
- Lightweight CSS and minimal round-trips
