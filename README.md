# 🍽️ Digital by Jeff - Restaurant POS System

[![Version](https://img.shields.io/badge/version-1.0.0-blue.svg)](https://github.com/jeffads92-spec/jwg)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net)
[![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-orange.svg)](https://mysql.com)

**Complete Restaurant Point of Sale System** - Modern, fast, and feature-rich POS system built for restaurants, cafes, and food businesses.

---

## ✨ Features

### 🎯 Core Features
- ✅ **Multi-User Management** - Admin, Manager, Cashier, Waiter, Chef roles
- ✅ **Menu Management** - Categories, items, pricing, images, availability
- ✅ **Order Management** - Dine-in, takeaway, delivery orders
- ✅ **Kitchen Display System** - Real-time order updates for kitchen
- ✅ **Payment Processing** - Cash, Card, QRIS, E-Wallet integration
- ✅ **Receipt Generation** - Auto-generate and print receipts
- ✅ **Table Management** - Track table status and assignments

### 🚀 Advanced Features
- ✅ **QR Self-Ordering** - Customers order via QR code scan
- ✅ **Inventory Management** - Stock tracking, auto-deduct, alerts
- ✅ **Recipe Management** - Link ingredients to menu items
- ✅ **Split Bill** - Split payment between multiple customers
- ✅ **Discount System** - Fixed, percentage, promo codes
- ✅ **Analytics & Reports** - Sales, revenue, top items, trends
- ✅ **Activity Logs** - Track all user actions

### 🌐 Multi-Language Support
- 🇮🇩 Bahasa Indonesia
- 🇬🇧 English
- Easy language switcher

---

## 📋 System Requirements

- **PHP**: 7.4 or higher
- **MySQL**: 5.7 or higher
- **Web Server**: Apache/Nginx
- **Extensions**: PDO, GD Library, JSON, cURL
- **Composer**: Optional (for dependencies)

---

## 🚀 Quick Installation

### 1. Clone Repository
```bash
git clone https://github.com/jeffads92-spec/jwg.git
cd jwg
```

### 2. Configure Database
```bash
# Copy environment file
cp .env.example .env

# Edit .env with your database credentials
nano .env
```

### 3. Import Database
```bash
mysql -u root -p jwg_resto < config/schema.sql
```

### 4. Set Permissions
```bash
chmod 755 uploads/
chmod 755 qr-codes/
chmod 755 logs/
```

### 5. Access System
```
Admin: http://localhost/jwg/admin/
Customer: http://localhost/jwg/customer/
```

**Default Login:**
- Username: `admin`
- Password: `admin123`

⚠️ **IMPORTANT**: Change the default password immediately!

---

## 🐳 Docker Installation

```bash
# Build and run with Docker
docker-compose up -d

# Access at http://localhost:8080
```

---

## 🚂 Railway Deployment

### Quick Deploy to Railway

1. **Fork this repository**

2. **Connect to Railway**
   - Go to [Railway.app](https://railway.app)
   - Click "New Project"
   - Select "Deploy from GitHub repo"
   - Choose your forked repository

3. **Add MySQL Database**
   - In your Railway project, click "New"
   - Select "Database" → "MySQL"
   - Railway will auto-configure connection

4. **Set Environment Variables**
   ```
   DB_HOST=mysql.railway.internal
   DB_PORT=3306
   DB_NAME=railway
   DB_USER=root
   DB_PASS=(auto-generated)
   JWT_SECRET=your-secret-key
   APP_URL=https://your-app.up.railway.app
   ```

5. **Import Database**
   - Use Railway's MySQL client or phpMyAdmin
   - Import `config/schema.sql`

6. **Deploy!**
   - Railway will automatically deploy your app
   - Access at: `https://your-app.up.railway.app`

---

## 📁 Project Structure

```
jwg-resto-pos/
├── api/                    # Backend API endpoints
│   ├── auth.php           # Authentication
│   ├── menu.php           # Menu management
│   ├── orders.php         # Order management
│   ├── payments.php       # Payment processing
│   ├── kitchen.php        # Kitchen display
│   ├── inventory.php      # Inventory management
│   ├── reports.php        # Analytics & reports
│   ├── qr-generator.php   # QR code generation
│   └── customer/          # Customer-facing APIs
│       ├── menu.php
│       └── order.php
│
├── admin/                 # Admin dashboard (HTML/JS)
│   ├── index.html        # Login page
│   ├── dashboard.html    # Main dashboard
│   ├── menu-management.html
│   ├── orders.html
│   ├── kitchen.html
│   ├── inventory.html
│   ├── reports.html
│   └── settings.html
│
├── customer/             # Customer-facing pages
│   ├── index.html       # Landing/redirect
│   ├── menu.html        # Browse menu
│   ├── cart.html        # Shopping cart
│   └── order-status.html
│
├── config/              # Configuration files
│   ├── database.php    # Database connection
│   └── schema.sql      # Database schema
│
├── assets/             # Static assets
│   ├── logo.png
│   └── favicon.ico
│
├── uploads/            # User uploads
│   ├── menu/          # Menu images
│   └── receipts/      # Receipt PDFs
│
├── qr-codes/          # Generated QR codes
│
├── .env.example       # Environment template
├── Dockerfile         # Docker configuration
├── railway.toml       # Railway configuration
└── README.md          # This file
```

---

## 🔑 API Endpoints

### Authentication
```
POST   /api/auth.php?action=login
POST   /api/auth.php?action=register
POST   /api/auth.php?action=logout
GET    /api/auth.php?action=verify
```

### Menu
```
GET    /api/menu.php?action=list
GET    /api/menu.php?action=get&id=1
POST   /api/menu.php?action=create
PUT    /api/menu.php?action=update&id=1
DELETE /api/menu.php?action=delete&id=1
```

### Orders
```
GET    /api/orders.php?action=list
GET    /api/orders.php?action=get&id=1
POST   /api/orders.php?action=create
PUT    /api/orders.php?action=update-status&id=1
POST   /api/orders.php?action=cancel&id=1
```

### Customer (Public)
```
GET    /api/customer/menu.php?action=list
POST   /api/customer/order.php?action=submit
GET    /api/customer/order.php?action=track&order_number=XXX
```

**Full API documentation**: See `/docs/api-docs.md`

---

## 🎨 Customer QR Ordering Flow

1. **Customer scans QR code** on table
2. **Views menu** with categories, prices, images
3. **Adds items to cart** with notes/preferences
4. **Submits order** with customer info
5. **Order appears** in admin & kitchen display
6. **Tracks order status** in real-time
7. **Calls waiter** if needed
8. **Pays bill** when ready

---

## 💳 Payment Integration

### Supported Payment Methods
- 💵 Cash
- 💳 Credit/Debit Card
- 📱 QRIS (Indonesian QR Payment)
- 🏪 GoPay, OVO, DANA, ShopeePay
- 🏦 Bank Transfer

### QRIS Integration
```php
// Configure in .env
MIDTRANS_SERVER_KEY=your-server-key
MIDTRANS_CLIENT_KEY=your-client-key
MIDTRANS_IS_PRODUCTION=false
```

**Setup Guide**: See `/docs/payment-setup.md`

---

## 📊 Reports & Analytics

- 📈 **Dashboard** - Today's sales, active orders, revenue
- 💰 **Sales Reports** - Daily, weekly, monthly, custom period
- 🏆 **Top Selling Items** - Best performers
- 📊 **Category Analysis** - Revenue by category
- ⏰ **Peak Hours** - Busiest times
- 💳 **Payment Methods** - Distribution
- 📱 **Order Sources** - Admin vs QR vs Online

---

## 🔒 Security Features

- ✅ JWT Authentication
- ✅ Password hashing (bcrypt)
- ✅ SQL injection prevention (PDO prepared statements)
- ✅ XSS protection
- ✅ CSRF tokens
- ✅ Role-based access control
- ✅ Activity logging
- ✅ Secure file uploads

---

## 🛠️ Development

### Running Locally
```bash
# Start PHP development server
php -S localhost:8000

# Or use Apache/Nginx
```

### Database Migrations
```bash
# Reset database
mysql -u root -p jwg_resto < config/schema.sql
```

### Generate QR Codes
```bash
# Access QR generator
http://localhost/api/qr-generator.php?action=generate-all
```

---

## 🐛 Troubleshooting

### Database Connection Error
```bash
# Check credentials in .env
# Verify MySQL is running
sudo service mysql status
```

### Permission Denied
```bash
# Fix folder permissions
chmod 755 uploads/ qr-codes/ logs/
```

### QR Codes Not Generating
```bash
# Check GD library
php -m | grep gd

# Install if missing
sudo apt-get install php-gd
```

---

## 📖 Documentation

- [Installation Guide](docs/installation.md)
- [User Manual](docs/user-manual.md)
- [API Documentation](docs/api-docs.md)
- [Payment Setup](docs/payment-setup.md)
- [Deployment Guide](docs/deployment.md)

---

## 🤝 Support

Need help? Contact us:

- 📧 Email: support@digitalbyjeff.com
- 💬 WhatsApp: +62 812-3456-7890
- 🌐 Website: https://digitalbyjeff.com
- 📱 Telegram: @digitalbyjeff

---

## 📝 License

This project is licensed under the **MIT License**.

```
Copyright (c) 2024 Digital by Jeff - Jefri Wahyu Gunawan

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
```

---

## 🎯 Roadmap

### Version 1.1 (Coming Soon)
- [ ] Mobile app (React Native)
- [ ] Online ordering website
- [ ] Multi-branch support
- [ ] Loyalty program
- [ ] Customer database
- [ ] Email marketing integration

### Version 2.0
- [ ] AI-powered sales forecasting
- [ ] Automated inventory ordering
- [ ] Advanced analytics with charts
- [ ] Integration with delivery platforms

---

## 🌟 Credits

**Developed by**: Jefri Wahyu Gunawan  
**Brand**: Digital by Jeff  
**Year**: 2024

Special thanks to all contributors and the open-source community!

---

## 📸 Screenshots

### Admin Dashboard
![Dashboard](docs/screenshots/dashboard.png)

### Menu Management
![Menu](docs/screenshots/menu.png)

### Kitchen Display
![Kitchen](docs/screenshots/kitchen.png)

### Customer QR Ordering
![QR Order](docs/screenshots/qr-order.png)

---

### ⭐ Star this repository if you find it useful!

**Made with ❤️ by Digital by Jeff**
