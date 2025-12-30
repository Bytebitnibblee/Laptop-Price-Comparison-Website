-- Fix the missing group_key column in laptops table
-- Run this in phpMyAdmin or MySQL command line

-- 1. Add the group_key column
ALTER TABLE laptops ADD COLUMN group_key VARCHAR(100) NOT NULL AFTER brand;

-- 2. Populate group_key for existing laptops based on their names
-- This uses the same logic as the Python scraper
UPDATE laptops SET group_key = CASE
    -- Extract brand and series pattern
    WHEN name LIKE 'ASUS VivoBook%' THEN 'ASUS VivoBook'
    WHEN name LIKE 'ASUS ZenBook%' THEN 'ASUS ZenBook'
    WHEN name LIKE 'ASUS ROG%' THEN 'ASUS ROG'
    WHEN name LIKE 'ASUS TUF%' THEN 'ASUS TUF'
    WHEN name LIKE 'Lenovo ThinkPad%' THEN 'Lenovo ThinkPad'
    WHEN name LIKE 'Lenovo IdeaPad%' THEN 'Lenovo IdeaPad'
    WHEN name LIKE 'Lenovo Yoga%' THEN 'Lenovo Yoga'
    WHEN name LIKE 'Lenovo Legion%' THEN 'Lenovo Legion'
    WHEN name LIKE 'Lenovo LOQ%' THEN 'Lenovo LOQ'
    WHEN name LIKE 'Dell Latitude%' THEN 'Dell Latitude'
    WHEN name LIKE 'Dell Inspiron%' THEN 'Dell Inspiron'
    WHEN name LIKE 'Dell XPS%' THEN 'Dell XPS'
    WHEN name LIKE 'HP Pavilion%' THEN 'HP Pavilion'
    WHEN name LIKE 'HP Envy%' THEN 'HP Envy'
    WHEN name LIKE 'HP EliteBook%' THEN 'HP EliteBook'
    WHEN name LIKE 'HP ProBook%' THEN 'HP ProBook'
    WHEN name LIKE 'HP Omen%' THEN 'HP Omen'
    WHEN name LIKE 'Acer Predator%' THEN 'Acer Predator'
    WHEN name LIKE 'Acer Nitro%' THEN 'Acer Nitro'
    WHEN name LIKE 'Acer Aspire%' THEN 'Acer Aspire'
    WHEN name LIKE 'Acer Swift%' THEN 'Acer Swift'
    WHEN name LIKE 'Apple MacBook Air%' THEN 'Apple MacBook Air'
    WHEN name LIKE 'Apple MacBook Pro%' THEN 'Apple MacBook Pro'
    -- Generic fallback: take brand + first word after brand
    ELSE CONCAT(
        brand, ' ',
        SUBSTRING_INDEX(
            TRIM(SUBSTRING(name, LENGTH(brand)+2)),
            ' ',
            1
        )
    )
END;
