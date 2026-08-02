# 🎯 Admin Dashboard - Complete System Guide

## Overview

A comprehensive admin dashboard system has been successfully created for the Lechon Delights e-commerce platform. The system provides complete management capabilities for orders, customers, products, franchise applications, and business analytics.

## 📋 What's New

### Admin System Features
- **Role-Based Access Control** - Only admin users can access the dashboard
- **Dashboard Overview** - Key metrics and recent activity at a glance
- **Orders Management** - Complete order tracking and status management
- **Users Management** - Customer account administration
- **Products Management** - Inventory and catalog control
- **Franchise Management** - Review and process franchise applications
- **Analytics** - Business performance reports and charts

### Security Features
- Admin-only authentication required
- Prepared SQL statements prevent injection
- Session-based security
- Input sanitization and validation
- Role verification on every admin page

## 🚀 Quick Start

### 1. Admin Login
```
URL: http://localhost/lechong/login.php
Email: admin@lechondelights.com
Password: 123
```

### 2. Access Dashboard
After login, you'll be automatically redirected to:
```
http://localhost/lechong/admin/index.php
```

### 3. Navigate Using Sidebar
The sidebar menu provides access to all admin functions:
- Dashboard
- Orders
- Users
- Products
- Franchise Applications
- Statistics
- Logout

## 📁 File Structure

### Admin Folder Contents (`/admin/`)

```
16 files total:

Core Files:
├── index.php                      Main dashboard page
├── auth.php                       Authentication functions
├── sidebar.php                    Navigation menu
└── logout.php                     Session logout

Management Pages:
├── orders.php                     Orders management
├── users.php                      Users management
├── products.php                   Products management
├── franchise_applications.php     Franchise management
└── statistics.php                 Analytics & reports

AJAX Handlers (for modals):
├── get_order_details.php          Order information
├── get_user_details.php           User information
└── get_application_details.php    Application information

Styling & Documentation:
├── style.css                      Dashboard styling
├── README.md                      Feature documentation
├── SETUP.md                       Setup guide
└── TEST_GUIDE.md                  Testing procedures
```

## 🎨 Dashboard Sections

### 1. Dashboard (Home)
**Purpose**: Overview of key business metrics

**What you see:**
- Today's orders and revenue
- Pending orders count
- Total customers
- Monthly revenue
- Franchise applications pending
- Recent orders list

**What you can do:**
- View all statistics
- Quick-link to recent orders
- Access other sections

### 2. Orders Management
**Purpose**: Complete order management and tracking

**Features:**
- View all orders (25+ demo orders available)
- Search by order number, customer, or email
- Filter by status (pending, confirmed, preparing, delivered, cancelled)
- View complete order details in modal:
  - Customer information
  - Delivery details
  - Order items
  - Payment information
- Update order status with dropdown

**Demo Data:**
- Various order statuses
- Different payment methods
- Multiple delivery options

### 3. Users Management
**Purpose**: Manage customer accounts

**Features:**
- List all customers (4+ demo users)
- Search by name or email
- Filter by account type (individual/organization)
- View user details:
  - Contact information
  - Order count
  - Total spent
  - Account status
- Activate/deactivate accounts

**Demo Data:**
- Multiple customer accounts
- Different account types
- Varying order histories

### 4. Products Management
**Purpose**: Manage product catalog and availability

**Features:**
- View products in grid layout
- Search by product name
- Filter by category
- Toggle product status (active/inactive)
- Display information:
  - Price
  - Category
  - Weight
  - Serving size

**Demo Data:**
- 13 products across categories:
  - Whole Lechon
  - Lechon Parts
  - Dishes
  - Rice & Sides
  - Sauces & Extras

### 5. Franchise Applications
**Purpose**: Review and process franchise opportunities

**Features:**
- View all applications (1 demo application)
- Search applications
- Filter by status (pending, approved, rejected)
- View complete application details:
  - Business information
  - Contact details
  - Investment amount
  - Business experience
  - Marketing plan
- Download uploaded documents:
  - DTI documents
  - BIR documents
  - Mayor's permits
  - Valid IDs
  - Address proofs
  - Bank proofs
- Approve/reject with admin notes

### 6. Statistics & Reports
**Purpose**: Analyze business performance

**Features:**
- Visual charts using Chart.js:
  - Daily orders (last 7 days)
  - Monthly revenue trends
- Data tables:
  - Top selling products
  - Order status breakdown
  - Payment methods used
- Real-time data from database

## 🔑 Key Features

### Search Functionality
All management pages include search:
- **Orders**: Search by order number, customer name, or email
- **Users**: Search by name or email
- **Products**: Search by product name
- **Franchise Apps**: Search by application number or business name

### Filtering Capabilities
Filter data to find exactly what you need:
- **Orders**: By status (5 options)
- **Users**: By account type (2 options)
- **Products**: By category
- **Franchise Apps**: By status (3 options)

### Quick Actions
One-click operations:
- View details in modal
- Update status
- Activate/deactivate items
- Download documents
- Send notifications

### Responsive Design
Works perfectly on:
- **Desktop** (1024px+) - Full layout
- **Tablet** (768-1023px) - Compact layout
- **Mobile** (<768px) - Mobile-optimized

## 📊 Database Tables Used

The admin dashboard interacts with:

1. **users** - Admin and customer accounts
   - email, password, full_name, phone, user_type

2. **orders** - Order records
   - order_number, customer_name, total_amount, status, delivery_date

3. **order_items** - Items in each order
   - product_name, quantity, price, total

4. **products** - Product catalog
   - name, price, category, is_active

5. **payments** - Payment records
   - payment_method, status, amount

6. **franchise_applications** - Franchise requests
   - application_number, business_name, status, capital_investment

7. **franchise_documents** - Uploaded documents
   - document_type, file_path

## 🔐 Security Implementation

### Authentication
- Admin-only access to `/admin/` folder
- Session-based login verification
- Automatic logout capability

### SQL Security
- Prepared statements prevent SQL injection
- Parameter binding for all queries
- Input validation on all forms

### Data Protection
- User role verification on every page
- HTML escaping for output
- Proper access control checks

### Session Management
- Secure session handling
- Automatic session verification
- Logout functionality

## 💻 Using the Admin Dashboard

### Common Tasks

#### Update an Order Status
1. Click **Orders** in sidebar
2. Find the order
3. Click **Change Status** dropdown
4. Select new status (confirmed, preparing, delivered, or cancelled)
5. Status updates immediately

#### Search for a Customer
1. Click **Users** in sidebar
2. Type customer name in search box
3. Click **Search** button
4. Results filter instantly
5. Click eye icon to view details

#### Toggle a Product
1. Click **Products** in sidebar
2. Find the product card
3. Click **Activate** or **Deactivate** button
4. Status changes immediately

#### Review Franchise Application
1. Click **Franchise Apps** in sidebar
2. Find application (filter by "pending" if needed)
3. Click eye icon to view details
4. Review business information and documents
5. Add notes and select Approve or Reject
6. Submit to process

#### View Analytics
1. Click **Statistics** in sidebar
2. View charts for daily orders and revenue
3. Scroll to see top products
4. View order status breakdown
5. Check payment methods

## 📈 Admin Dashboard Statistics

### What Data is Shown
- **Today's Orders**: Count and total revenue
- **Pending Orders**: How many need attention
- **Total Customers**: Active customer base
- **Monthly Revenue**: This month's total
- **Pending Applications**: Franchise opportunities

### How to Interpret Data
- Green badges = Positive status (delivered, active)
- Yellow badges = Pending/attention needed
- Red badges = Cancelled/issues
- Numbers show counts/totals

## 🛠️ Customization

### Change Admin Credentials
Update the database:
```sql
UPDATE users 
SET email = 'new_email@example.com', 
    password = PASSWORD('new_password'),
    full_name = 'New Name'
WHERE id = 1 AND user_type = 'admin'
```

### Add More Admin Users
Insert into database:
```sql
INSERT INTO users (email, password, full_name, phone, user_type, account_type, is_active)
VALUES ('admin2@lechondelights.com', PASSWORD('password123'), 'Admin Name', '09123456789', 'admin', 'individual', 1)
```

### Customize Colors
Edit `admin/style.css` CSS variables:
```css
:root {
    --primary: #1976d2;      /* Main color */
    --secondary: #424242;    /* Secondary color */
    --success: #388e3c;      /* Success color */
    --danger: #dc3545;       /* Danger color */
    --warning: #f57c00;      /* Warning color */
}
```

## ❓ FAQ

### Q: How do I login as admin?
A: Use email `admin@lechondelights.com` and password `123` at the login page.

### Q: Can non-admin users access the dashboard?
A: No, access is restricted to users with `user_type = 'admin'` only.

### Q: Where do I find uploaded documents?
A: In Franchise Applications section, click view icon, then click download icon on documents.

### Q: How do I update an order status?
A: Go to Orders, click "Change Status" dropdown for the order, and select new status.

### Q: Can I search across multiple fields?
A: Yes, each page supports search and filter combinations for precise results.

### Q: Is the dashboard mobile-friendly?
A: Yes, it's fully responsive and works on desktop, tablet, and mobile devices.

### Q: What should I change after first login?
A: Change the admin password immediately for security purposes.

### Q: How do I add more admin users?
A: Insert new records into the users table with user_type = 'admin'.

## 📚 Documentation Files

The system includes comprehensive documentation:

1. **README.md** (in `/admin/`)
   - Detailed feature descriptions
   - Database table information
   - Function documentation

2. **SETUP.md** (in `/admin/`)
   - Installation instructions
   - Configuration guide
   - Customization tips

3. **TEST_GUIDE.md** (in `/admin/`)
   - Testing procedures
   - Test cases
   - Troubleshooting

4. **ADMIN_IMPLEMENTATION.md** (in root)
   - Implementation summary
   - Feature checklist
   - Architecture overview

## ⚠️ Important Notes

### Default Admin Credentials
```
Email: admin@lechondelights.com
Password: 123
```
**Change this immediately for security!**

### Browser Requirements
- Chrome, Firefox, Safari, or Edge (latest versions)
- JavaScript enabled
- Cookies enabled for sessions

### Database Requirements
- MySQL/MariaDB running
- lechon_db database exists
- All required tables created

### PHP Requirements
- PHP 7.4 or higher
- MySQLi extension
- Session support

## 🚨 Security Reminders

1. **Change Admin Password** - Do this first!
2. **Use HTTPS** - Recommended for production
3. **Regular Backups** - Back up database regularly
4. **Monitor Access** - Check who's accessing the dashboard
5. **Update Regularly** - Keep all software updated

## 📞 Support

For issues or questions:
1. Check the documentation files
2. Review the TEST_GUIDE.md for troubleshooting
3. Check browser console (F12) for errors
4. Verify database connectivity
5. Check file permissions

## ✅ Ready to Go!

Your admin dashboard is:
- ✅ Fully implemented
- ✅ Secured and authenticated
- ✅ Well documented
- ✅ Responsive and modern
- ✅ Ready for production

**Start managing your business now!**

---

## 🎯 Next Steps

1. **Login** with admin credentials
2. **Explore** all dashboard sections
3. **Test** search and filter features
4. **Update** the admin password
5. **Create** additional admin accounts as needed
6. **Customize** colors and branding if desired
7. **Train** team members on dashboard usage

---

**Last Updated**: January 19, 2026
**Version**: 1.0
**Status**: Production Ready

For the complete implementation details, see `ADMIN_IMPLEMENTATION.md`
