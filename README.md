# CampusCart

# School Uniform Availability Monitoring and Ordering System

CampusCart is a web-based school uniform availability monitoring and ordering system designed to help students conveniently browse available school uniforms, check product availability, and place orders online. It also provides the school cooperative staff with tools to manage products, inventory, student orders, and walk-in sales.

# Project Overview

CampusCart aims to improve the purchasing process at the school cooperative by providing an organized platform for product browsing, inventory monitoring, online ordering, and transaction management.

The system has two user roles:

- Student – Can browse products, manage favorites and shopping carts, place orders, and track order status.
- Admin (Cooperative Staff) – Can manage products, inventory, student orders, payments, and walk-in sales.

# Main Features
CampusCart provides a centralized platform for monitoring school uniform availability and managing student purchases. Its main feature is the integration of online ordering and inventory monitoring, allowing students to check product availability and submit orders while cooperative staff can manage stock levels and process transactions.

The system also supports automatic inventory deduction when orders are submitted, manual stock adjustments, GCash payment verification, and low-stock notifications to help maintain accurate inventory records and streamline cooperative operations.

# Student Features
- Student registration and login
- Product catalogue
- Product browsing by category
- Shopping cart management
- Favorites
- Online checkout
- Cash on Pickup and GCash payment options
- Order history and order tracking
- Profile management
- Notifications
- Light and dark theme preference

# Admin Features
- Admin dashboard
- Product management
- Inventory and product variant management
- Manual stock adjustments
- Online order management
- GCash payment verification
- Walk-in sales recording
- Walk-in sales history
- Low-stock and out-of-stock notifications

# Product Categories

CampusCart has three product categories:

1. School Uniform
2. P.E. Uniforms
3. Accessories

# Technologies Used

|     Technology   |      Purpose       |
|------------------|--------------------|
| HTML         |     Web page structure |
| CSS          |     Website styling    |
| JavaScript   |   Interactive features |
| PHP          |    Backend development |
| MySQL        |    Database management |
| XAMPP | Local development environment |
| Visual Studio Code |   Code editor    |
| Git          |    Version control     |
| GitHub       | Source code repository |

# Payment Methods

CampusCart supports two payment methods:

- Cash on Pickup – Students pay when collecting their orders at the school cooperative.
- GCash – Students submit their transaction reference number and payment screenshot for Admin verification.

# Database

CampusCart uses MySQL and consists of 15 related database tables:

1. users
2. categories
3. products
4. product_variants
5. inventory
6. stock_adjustments
7. carts
8. cart_items
9. orders
10. order_items
11. payments
12. walkin_sales
13. walkin_sale_items
14. notifications

# Installation and Setup
CampusCart can be installed and run locally using XAMPP, PHP, and MySQL, or accessed online through its deployed website hosted on InfinityFree. Follow the setup instructions below for local installation, or visit the live application using the provided deployment link.

# Requirements

- XAMPP
- PHP
- MySQL
- Visual Studio Code
- Web browser

# Local Installation

1. Install and start XAMPP.
2. Start Apache and MySQL.
3. Clone or download the CampusCart repository.
4. Place the project folder inside the XAMPP `htdocs` directory.
5. Create a MySQL database named `campuscart_db` using phpMyAdmin.
6. Import the database SQL file when it becomes available.
7. Configure the database connection in `config/database.php`.
8. Open the project in your browser:
   `http://localhost/campuscart/`

# Database Configuration

Update the database connection settings in `config/database.php` according to your local MySQL configuration.

Do not upload database passwords, credentials, or other sensitive configuration files to the public repository.

# Project Status

CampusCart is currently under development.

The initial database structure has been created, and the project is being developed using PHP and MySQL.

Additional features, testing, and deployment will be completed throughout the development process.

# Repository

This repository contains the source code and development files for the CampusCart project.

Version control is managed using Git and GitHub.

# Screenshots

Screenshots of the completed system will be added here as development progresses.

# Live Demo

The live demo link will be added after the system has been deployed.

# License

This project is developed for academic purposes as part of a Web System Technology final project.