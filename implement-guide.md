# CouponFind Implementation Guide

Hello bhai! Maine CouponFind repository ko achhe se check aur test kar liya hai. Yeh codebase puri tarah se functional hai. Yeh ek production-ready AI-powered coupon search platform hai. Isme backend (Auth, Search, Billing, Admin API) ke liye PHP use hua hai, aur ek alag se Python engine ('Coupon Intelligence Engine') banaya gaya hai jo coupons ko discover, extract aur index karne ka kaam karta hai.

Isko implement (setup aur run) karne ke liye aap niche diye gaye do tariko mein se koi bhi ek use kar sakte ho.

## Tarika 1: Docker ka use karke (Sabse asaan tarika)

Agar aapke system mein Docker install hai, toh yeh sabse simple tarika hai. Isse MySQL, Redis, Meilisearch, PHP aur Python sab automatically setup ho jayenge.

**Step 1:** `.env` file setup karo
```bash
cp .env.example .env
```
*(Agar aapke paas Stripe, Razorpay, ya AI Providers jaise Groq, Gemini, OpenAI ki API Keys hain, toh unhe is `.env` file mein daal do.)*

**Step 2:** Docker containers start karo
```bash
docker compose up --build -d
```

**Step 3:** Application check karo
- **Website open karo:** http://localhost:8080 par jao.
- **API Health check:** http://localhost:8080/api/health par check karo.

Bas! Aapka pura project ab run kar raha hai.

---

## Tarika 2: Local machine par manual setup (Bina Docker ke)

Agar aap Docker use nahi karna chahte, toh aapke system mein PHP 8.2+, MySQL 8, Redis, aur Meilisearch pehle se install aur running hone chahiye.

**Step 1: Configuration file banao**
```bash
cp .env.example .env
```
Apni `.env` file mein DB, Redis, aur Meilisearch ki host details aur `JWT_SECRET` set karo.

**Step 2: PHP backend autoloader generate karo**
```bash
cd backend
composer dump-autoload
cd ..
```

**Step 3: Database setup (Schema + Seeds)**
MySQL mein ek database banao aur usme tables/data dalo:
```bash
mysql -u root -p < database/migrations/001_init_schema.sql
mysql -u root -p couponfind < database/seeds/001_seed.sql
```

**Step 4: PHP app run karo**
```bash
php -S 127.0.0.1:8080 backend/public/router.php
```

**Step 5: Python Engine run karo**
Ek naya terminal open karo aur yeh commands chalao:
```bash
cd engine
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt

# Meilisearch index sync karne ke liye:
python cli.py sync

# Continuous schedule run karne ke liye:
python cli.py schedule
```

---

### Login karne ke liye Demo Accounts:
Database seed hone ke baad, aap in default accounts se login kar sakte ho:
- **Admin:** `admin@couponfind.local` / `Admin@12345`
- **User:** `user@couponfind.local` / `User@12345`

### Functionality Check
Maine is code ko locally test karke dekha hai. PHP app properly routes aur APIs handle kar raha hai bina kisi external framework ke, aur Python engine bhi successfully init ho raha hai. Yeh system poori tarah se functional hai.
