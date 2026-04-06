-- HubMedia BV Boekhouding - Database Schema
-- Database: hubmed01_boekhouding

CREATE TABLE IF NOT EXISTS invoices (
    id VARCHAR(20) PRIMARY KEY,
    type ENUM('inkoop', 'verkoop') NOT NULL,
    leverancier VARCHAR(255) NOT NULL,
    nummer VARCHAR(100) DEFAULT '',
    datum DATE NOT NULL,
    vervaldatum DATE DEFAULT NULL,
    btw_pct DECIMAL(5,2) DEFAULT 21.00,
    bedrag_excl DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    btw_bedrag DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    bedrag_incl DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status ENUM('onbetaald', 'betaald') DEFAULT 'onbetaald',
    omschrijving TEXT DEFAULT NULL,
    has_file TINYINT(1) DEFAULT 0,
    file_name VARCHAR(255) DEFAULT '',
    grootboek_id VARCHAR(20) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_type (type),
    INDEX idx_datum (datum),
    INDEX idx_status (status),
    INDEX idx_leverancier (leverancier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transactions (
    id VARCHAR(20) PRIMARY KEY,
    datum DATE NOT NULL,
    naam VARCHAR(255) NOT NULL DEFAULT '',
    bedrag DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    type VARCHAR(10) DEFAULT '',
    factuur_id VARCHAR(20) DEFAULT NULL,
    ai_gekoppeld TINYINT(1) DEFAULT 0,
    goedgekeurd TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_datum (datum),
    INDEX idx_factuur (factuur_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS relaties (
    id VARCHAR(20) PRIMARY KEY,
    type ENUM('klant', 'leverancier') NOT NULL,
    naam VARCHAR(255) NOT NULL,
    contact VARCHAR(255) DEFAULT '',
    email VARCHAR(255) DEFAULT '',
    tel VARCHAR(50) DEFAULT '',
    adres VARCHAR(255) DEFAULT '',
    plaats VARCHAR(255) DEFAULT '',
    btwnr VARCHAR(50) DEFAULT '',
    kvk VARCHAR(50) DEFAULT '',
    iban VARCHAR(50) DEFAULT '',
    term VARCHAR(10) DEFAULT '30',
    notitie TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_type (type),
    INDEX idx_naam (naam)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS grootboek (
    id VARCHAR(20) PRIMARY KEY,
    nummer VARCHAR(20) NOT NULL,
    naam VARCHAR(255) NOT NULL,
    categorie ENUM('activa', 'passiva', 'omzet', 'kosten', 'btw', 'overig') DEFAULT 'overig',
    btwcode VARCHAR(10) DEFAULT '',
    omschrijving TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_nummer (nummer)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
