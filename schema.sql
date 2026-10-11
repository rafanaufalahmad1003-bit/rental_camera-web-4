-- Rental Camera Starter Schema
-- MySQL 8.0+
CREATE DATABASE IF NOT EXISTS rental_camera
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE rental_camera;

CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  username VARCHAR(60) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','staff') NOT NULL DEFAULT 'staff',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE customers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(30) NOT NULL,
  email VARCHAR(150) NULL,
  identity_number VARCHAR(60) NULL,
  address TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_customers_name (name),
  INDEX idx_customers_phone (phone)
) ENGINE=InnoDB;

CREATE TABLE categories (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  description VARCHAR(255) NULL
) ENGINE=InnoDB;

-- Equipment describes a product/model (e.g. Sony A7 III).
CREATE TABLE equipment (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id BIGINT UNSIGNED NOT NULL,
  sku VARCHAR(50) NOT NULL UNIQUE,
  brand VARCHAR(80) NOT NULL,
  model VARCHAR(120) NOT NULL,
  daily_rate DECIMAL(12,2) NOT NULL,
  deposit_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  description TEXT NULL,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_equipment_rate CHECK (daily_rate >= 0),
  CONSTRAINT chk_equipment_deposit CHECK (deposit_amount >= 0),
  CONSTRAINT fk_equipment_category FOREIGN KEY (category_id) REFERENCES categories(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  INDEX idx_equipment_brand_model (brand, model)
) ENGINE=InnoDB;

CREATE TABLE suppliers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(140) NOT NULL,
  contact_name VARCHAR(120) NULL,
  phone VARCHAR(30) NOT NULL,
  email VARCHAR(150) NULL,
  address TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_suppliers_name (name)
) ENGINE=InnoDB;

-- Equipment may be sourced from several suppliers, and one supplier may provide many items.
CREATE TABLE equipment_suppliers (
  equipment_id BIGINT UNSIGNED NOT NULL,
  supplier_id BIGINT UNSIGNED NOT NULL,
  supplier_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (equipment_id, supplier_id),
  CONSTRAINT chk_supplier_price CHECK (supplier_price >= 0),
  CONSTRAINT fk_es_equipment FOREIGN KEY (equipment_id) REFERENCES equipment(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_es_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Each physical body/lens/accessory is tracked by its own serial number.
CREATE TABLE equipment_units (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  equipment_id BIGINT UNSIGNED NOT NULL,
  serial_number VARCHAR(100) NOT NULL UNIQUE,
  condition_note VARCHAR(255) NULL,
  status ENUM('available','rented','maintenance','retired') NOT NULL DEFAULT 'available',
  CONSTRAINT fk_units_equipment FOREIGN KEY (equipment_id) REFERENCES equipment(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  INDEX idx_units_availability (equipment_id, status)
) ENGINE=InnoDB;

CREATE TABLE rentals (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rental_code VARCHAR(30) NOT NULL UNIQUE,
  customer_id BIGINT UNSIGNED NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  start_at DATETIME NOT NULL,
  due_at DATETIME NOT NULL,
  returned_at DATETIME NULL,
  status ENUM('booked','active','completed','cancelled','late') NOT NULL DEFAULT 'booked',
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  deposit_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_rental_dates CHECK (due_at > start_at),
  CONSTRAINT chk_rental_amounts CHECK (subtotal >= 0 AND deposit_total >= 0),
  CONSTRAINT fk_rentals_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_rentals_user FOREIGN KEY (created_by) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  INDEX idx_rentals_status_dates (status, start_at, due_at),
  INDEX idx_rentals_customer (customer_id)
) ENGINE=InnoDB;

CREATE TABLE rental_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rental_id BIGINT UNSIGNED NOT NULL,
  equipment_unit_id BIGINT UNSIGNED NOT NULL,
  daily_rate DECIMAL(12,2) NOT NULL,
  rental_days INT UNSIGNED NOT NULL,
  line_total DECIMAL(12,2) NOT NULL,
  CONSTRAINT chk_rental_item_amount CHECK (daily_rate >= 0 AND line_total >= 0),
  CONSTRAINT chk_rental_item_days CHECK (rental_days > 0),
  CONSTRAINT fk_rental_items_rental FOREIGN KEY (rental_id) REFERENCES rentals(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_rental_items_unit FOREIGN KEY (equipment_unit_id) REFERENCES equipment_units(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  INDEX idx_rental_items_unit (equipment_unit_id),
  INDEX idx_rental_items_rental (rental_id)
) ENGINE=InnoDB;

CREATE TABLE payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rental_id BIGINT UNSIGNED NOT NULL,
  received_by BIGINT UNSIGNED NOT NULL,
  payment_type ENUM('deposit','rental_fee','late_fee','refund') NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  method ENUM('cash','transfer','card','other') NOT NULL DEFAULT 'cash',
  paid_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  note VARCHAR(255) NULL,
  CONSTRAINT chk_payment_amount CHECK (amount > 0),
  CONSTRAINT fk_payments_rental FOREIGN KEY (rental_id) REFERENCES rentals(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_payments_user FOREIGN KEY (received_by) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  INDEX idx_payments_rental (rental_id, paid_at)
) ENGINE=InnoDB;

CREATE TABLE maintenance_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  equipment_unit_id BIGINT UNSIGNED NOT NULL,
  recorded_by BIGINT UNSIGNED NOT NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  cost DECIMAL(12,2) NOT NULL DEFAULT 0,
  description TEXT NOT NULL,
  CONSTRAINT chk_maintenance_cost CHECK (cost >= 0),
  CONSTRAINT fk_maintenance_unit FOREIGN KEY (equipment_unit_id) REFERENCES equipment_units(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_maintenance_user FOREIGN KEY (recorded_by) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  INDEX idx_maintenance_unit (equipment_unit_id, started_at)
) ENGINE=InnoDB;
