-- Jalankan file ini sekali jika database rental_camera dari paket awal sudah dibuat.
-- File ini menambahkan tabel pemasok dan relasi many-to-many tanpa menghapus data lama.
USE rental_camera;

CREATE TABLE IF NOT EXISTS suppliers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(140) NOT NULL,
  contact_name VARCHAR(120) NULL,
  phone VARCHAR(30) NOT NULL,
  email VARCHAR(150) NULL,
  address TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_suppliers_name (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS equipment_suppliers (
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
