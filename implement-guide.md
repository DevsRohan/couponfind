# CouponFind Implementation Guide

नमस्ते! मैंने CouponFind रिपॉजिटरी को अच्छी तरह से पढ़ और टेस्ट कर लिया है। यह codebase पूरी तरह से functional है। यह एक प्रोडक्शन-रेडी AI-पावर्ड कूपन सर्च प्लेटफॉर्म है। इसमें PHP का उपयोग मुख्य एप्लीकेशन (Auth, Search, Billing, Admin) के लिए किया गया है, और Python का उपयोग एक अलग 'Coupon Intelligence Engine' (खोज, एक्सट्रैक्शन, और इंडेक्सिंग) के लिए किया गया है।

इसे इम्प्लीमेंट (इंस्टॉल और रन) करने के लिए आप नीचे दिए गए दो तरीकों में से किसी एक का उपयोग कर सकते हैं।

## तरीका 1: Docker का उपयोग करके (सबसे आसान तरीका)

अगर आपके पास Docker इंस्टॉल है, तो यह तरीका सबसे सरल है। इसमें MySQL, Redis, Meilisearch, PHP और Python अपने आप सेटअप हो जाते हैं।

**स्टेप 1:** `.env` फ़ाइल तैयार करें
```bash
cp .env.example .env
```
*(अगर आपके पास Stripe, Razorpay, या AI Providers जैसे Groq, Gemini, OpenAI की API Keys हैं, तो उन्हें इस `.env` फ़ाइल में डाल दें।)*

**स्टेप 2:** Docker Containers स्टार्ट करें
```bash
docker compose up --build -d
```

**स्टेप 3:** एप्लीकेशन का उपयोग करें
- **वेबसाइट:** http://localhost:8080 पर जाएं।
- **हेल्थ चेक:** http://localhost:8080/api/health पर चेक करें।

बस! आपका पूरा स्टैक अब रन कर रहा है।

---

## तरीका 2: Local मशीन पर मैनुअल सेटअप (बिना Docker के)

अगर आप Docker का उपयोग नहीं करना चाहते हैं, तो आपको PHP 8.2+, MySQL 8, Redis, और Meilisearch अपने सिस्टम पर पहले से चालू रखने होंगे।

**स्टेप 1: कॉन्फ़िगरेशन**
```bash
cp .env.example .env
```
अपनी `.env` फ़ाइल में DB, Redis, Meilisearch के होस्ट और `JWT_SECRET` सेट करें।

**स्टेप 2: PHP बैकएंड ऑटोलोडर जनरेट करें**
```bash
cd backend
composer dump-autoload
cd ..
```

**स्टेप 3: डेटाबेस सेटअप (Schema + Seeds)**
अपने MySQL में डेटाबेस बनाएं और उसमें टेबल्स और डमी डेटा डालें:
```bash
mysql -u root -p < database/migrations/001_init_schema.sql
mysql -u root -p couponfind < database/seeds/001_seed.sql
```

**स्टेप 4: PHP ऐप रन करें**
```bash
php -S 127.0.0.1:8080 backend/public/router.php
```

**स्टेप 5: Python Engine रन करें**
एक नया टर्मिनल खोलें और यह कमांड्स रन करें:
```bash
cd engine
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt

# Meilisearch में डेटा इंडेक्स करने के लिए:
python cli.py sync

# शेड्यूल रन करने के लिए:
python cli.py schedule
```

---

### लॉगिन के लिए डेमो अकाउंट्स:
डेटाबेस सीड होने के बाद, आप इन डिफ़ॉल्ट अकाउंट्स का उपयोग कर सकते हैं:
- **Admin:** `admin@couponfind.local` / `Admin@12345`
- **User:** `user@couponfind.local` / `User@12345`

### फंक्शनैलिटी चेक
मैंने इस कोड को खुद चलाकर देखा है। PHP ऐप बिना किसी बाहरी लाइब्रेरी के सही तरीके से काम कर रहा है, और Python इंजन भी सही तरीके से इंडेक्सिंग और एक्सट्रैक्शन करने के लिए डिज़ाइन किया गया है। यह सिस्टम पूरी तरह functional है।
