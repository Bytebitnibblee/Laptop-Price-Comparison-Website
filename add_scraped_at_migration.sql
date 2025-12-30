-- Migration to add scraped_at column to prices table
-- Run this after the initial database setup if you have existing data

USE laptop_comparison;

-- Add the scraped_at column to the prices table
ALTER TABLE prices ADD COLUMN scraped_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER product_url;

-- Optional: Update existing records to have a scraped_at value (use current timestamp)
-- Uncomment the line below if you want to set scraped_at for existing records
-- UPDATE prices SET scraped_at = last_updated WHERE scraped_at IS NULL;
