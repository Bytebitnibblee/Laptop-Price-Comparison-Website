#!/usr/bin/env python3
"""
Multi-Website Laptop Scraper - STREAMLINED VERSION
Working scrapers only: Mudita, Daraz, Nagmani, Yantra Nepal, Onin
"""

import time
import re
from datetime import datetime
from bs4 import BeautifulSoup
import mysql.connector
import numpy as np

from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.common.by import By
from webdriver_manager.chrome import ChromeDriverManager

# ML — sentence similarity model
# This model converts laptop titles into numbers so we can
# compare how similar two titles are to each other
try:
    from sentence_transformers import SentenceTransformer, util
    ML_MODEL = SentenceTransformer('all-MiniLM-L6-v2')
    ML_AVAILABLE = True
    print("✓ ML model loaded — using smart similarity grouping")
except Exception as e:
    ML_AVAILABLE = False
    print(f"⚠ ML model not available ({e}) — falling back to rule-based grouping")

DB_CONFIG = {
    "host": "localhost",
    "user": "root",
    "password": "",
    "database": "laptop_comparison",
}

# Number of laptops to scrape per site (each site gets this many)
PER_SITE_MAX_RESULTS = 30

# Mudita: start from this page (2 = skip page 1)
MUDITA_START_PAGE = 2

def create_driver():
    chrome_options = Options()
    # Remove headless for debugging - you'll see the browser
    # chrome_options.add_argument("--headless=new")
    chrome_options.add_argument("--disable-blink-features=AutomationControlled")
    chrome_options.add_argument("--start-maximized")
    chrome_options.add_experimental_option("excludeSwitches", ["enable-automation"])
    chrome_options.add_experimental_option('useAutomationExtension', False)
    chrome_options.add_argument("user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36")

    try:
        driver = webdriver.Chrome(
            service=Service(ChromeDriverManager().install()),
            options=chrome_options
        )
    except Exception as e:
        print(f"Failed to create driver with ChromeDriverManager: {e}")
        print("Trying with system chromedriver...")
        try:
            driver = webdriver.Chrome(options=chrome_options)
        except Exception as e2:
            print(f"Failed to create driver with system chromedriver: {e2}")
            print("Please install ChromeDriver manually or check your Chrome installation.")
            return None

    if driver:
        # Prevent detection
        try:
            driver.execute_script("Object.defineProperty(navigator, 'webdriver', {get: () => undefined})")
        except:
            pass

    return driver


class BaseScraper:
    def __init__(self, retailer_name):
        self.retailer = retailer_name
        self.driver = create_driver()
        if not self.driver:
            print(f"Failed to create driver for {retailer_name}")
        try:
            self.conn = mysql.connector.connect(**DB_CONFIG)
            self.cursor = self.conn.cursor(dictionary=True)
        except mysql.connector.Error as e:
            print(f"Database connection failed: {e}")
            print("Continuing without database connection...")
            self.conn = None
            self.cursor = None

    def clean_price(self, price_text):
        if not price_text:
            return None
        price_match = re.search(r'(?:Rs\.?\s*|₹|रू\.?)\s*([\d,]+(?:\.\d+)?)', price_text.strip())
        if price_match:
            numeric_part = price_match.group(1).replace(',', '')
            try:
                return float(numeric_part)
            except:
                pass
        cleaned = re.sub(r'[^\d.]', '', price_text)
        try:
            return float(cleaned)
        except:
            return None

    def extract_brand(self, name):
        brands = ["Dell", "HP", "Lenovo", "ASUS", "Acer", "Apple",
                  "MSI", "Samsung", "Microsoft", "Razer", "LG", "Alienware"]
        name_lower = name.lower()
        for brand in brands:
            if brand.lower() in name_lower:
                return brand
        return "Other"

    def extract_base_model(self, name):
        """
        Groups laptops by Brand + Model Family.

        Examples:
          Acer Aspire A16-51GM-74UU      →  Acer Aspire
          Acer Aspire A315-24P-R1H8      →  Acer Aspire   ← same group
          ASUS VivoBook X1404VA          →  ASUS VivoBook
          ASUS Vivobook 15 X1504VA       →  ASUS VivoBook  ← same group (casing ignored)
          Dell Inspiron 15 3520          →  Dell Inspiron
          Dell Inspiron 3520 i5 8GB      →  Dell Inspiron  ← same group
          HP Pavilion 15-eh3047AU        →  HP Pavilion
          Lenovo IdeaPad 5 15ITL05       →  Lenovo IdeaPad
          MSI Stealth 15 A13VE           →  MSI Stealth
          Apple MacBook Air M2           →  Apple MacBook
        """

        # Known model families per brand
        FAMILIES = [
            # Dell
            "Inspiron", "Vostro", "Latitude", "XPS",
            # HP
            "Pavilion", "Envy", "Omen", "Spectre", "EliteBook", "ProBook",
            # Lenovo
            "IdeaPad", "ThinkPad", "ThinkBook", "Yoga", "LOQ",
            # ASUS
            "VivoBook", "ZenBook", "ProArt", "TUF", "ROG",
            # Acer
            "Aspire", "Nitro", "Swift", "Predator",
            # Apple
            "MacBook",
            # MSI
            "Prestige", "Stealth", "Raider", "Titan", "Katana", "Creator",
            # Samsung
            "Galaxy Book",
            # Microsoft
            "Surface",
        ]

        brand = self.extract_brand(name)

        if brand == "Other":
            # No known brand — fallback to first word
            return name.split()[0] if name.split() else name

        name_lower = name.lower()

        for family in FAMILIES:
            if family.lower() in name_lower:
                # Return "Brand Family" with original casing
                # e.g. "ASUS VivoBook" not "ASUS vivobook"
                return f"{brand} {family}"

        # Brand found but no matching family — group under brand alone
        # e.g. "Dell" if title is just "Dell Laptop XYZ"
        return brand

    def extract_specs(self, title):
        """
        Pulls key specs out of a full laptop title.

        Example:
          "ASUS VivoBook 14 X1404VA 13th Gen i5-1334U 8GB 512GB FHD"
          →  {
               "model_number": "x1404va",
               "processor":    "i5",
               "gen":          "13",
               "ram":          "8",
               "storage":      "512",
               "resolution":   "fhd"
             }
        """
        t = title.lower()

        # Model number — alphanumeric codes like X1404VA, AN515-58, E1504FA
        model_number = None
        model_match = re.search(r'\b([a-z]{1,4}\d{3,5}[a-z0-9\-]*)\b', t)
        if model_match:
            model_number = model_match.group(1)

        # Processor family — i3, i5, i7, i9, ryzen 3/5/7/9
        processor = None
        proc_match = re.search(r'\b(i3|i5|i7|i9|ryzen\s*3|ryzen\s*5|ryzen\s*7|ryzen\s*9|core\s*ultra\s*\d+)\b', t)
        if proc_match:
            processor = proc_match.group(1).replace(" ", "")

        # Generation — 12th, 13th, 14th Gen or 7000, 7500 series for Ryzen
        gen = None
        gen_match = re.search(r'(\d{2})th\s*gen', t)
        if gen_match:
            gen = gen_match.group(1)

        # RAM — 8gb, 16gb, 32gb
        ram = None
        ram_match = re.search(r'(\d+)\s*gb\s*(?:ram|ddr|lpddr|sodimm)?', t)
        if ram_match:
            ram = ram_match.group(1)

        # Storage — 256gb, 512gb, 1tb
        storage = None
        storage_match = re.search(r'(\d+)\s*(?:gb|tb)\s*(?:ssd|hdd|nvme|g4)?', t)
        if storage_match:
            storage = storage_match.group(1)

        # Display resolution keyword
        resolution = None
        if "4k" in t or "uhd" in t:
            resolution = "4k"
        elif "2k" in t or "wqhd" in t or "2560" in t:
            resolution = "2k"
        elif "fhd" in t or "1920" in t or "1080" in t:
            resolution = "fhd"
        elif "hd" in t:
            resolution = "hd"

        return {
            "model_number": model_number,
            "processor":    processor,
            "gen":          gen,
            "ram":          ram,
            "storage":      storage,
            "resolution":   resolution,
        }

    def score_similarity(self, title_new, title_existing, price_new, price_existing):
        """
        Scores how likely two laptops are the same product.
        Uses full titles + specs + price difference.

        Scoring:
          Model number match  → 50 points  (strongest signal)
          Brand+Family match  → 20 points
          Processor match     → 15 points
          Price within 25k    → 15 points
          ------------------------------------------
          Total 70+ = same laptop

        Hard rules (automatic fail regardless of score):
          Price difference > Rs.40,000 → NOT same laptop
          Different processors (i5 vs i7) → NOT same laptop
          Different model numbers        → NOT same laptop
        """
        score = 0
        reasons = []

        specs_new      = self.extract_specs(title_new)
        specs_existing = self.extract_specs(title_existing)

        # --- HARD FAILS first ---

        # Price too far apart
        price_diff = abs(price_new - price_existing)
        if price_diff > 40000:
            return 0, f"Price gap Rs.{price_diff:,.0f} too large"

        # Different model numbers (if both have one)
        if (specs_new["model_number"] and specs_existing["model_number"]
                and specs_new["model_number"] != specs_existing["model_number"]):
            return 0, f"Different model numbers: {specs_new['model_number']} vs {specs_existing['model_number']}"

        # Different processor families (i5 vs i7 = different laptop)
        if (specs_new["processor"] and specs_existing["processor"]
                and specs_new["processor"] != specs_existing["processor"]):
            return 0, f"Different processors: {specs_new['processor']} vs {specs_existing['processor']}"

        # --- SCORING ---

        # Model number match (50 pts) — strongest signal
        if (specs_new["model_number"] and specs_existing["model_number"]
                and specs_new["model_number"] == specs_existing["model_number"]):
            score += 50
            reasons.append(f"model match ({specs_new['model_number']})")

        # Brand + Family match (20 pts)
        family_new      = self.extract_base_model(title_new)
        family_existing = self.extract_base_model(title_existing)
        if family_new == family_existing:
            score += 20
            reasons.append(f"family match ({family_new})")

        # Processor match (15 pts)
        if (specs_new["processor"] and specs_existing["processor"]
                and specs_new["processor"] == specs_existing["processor"]):
            score += 15
            reasons.append(f"processor match ({specs_new['processor']})")

        # Price within Rs.25,000 (15 pts)
        if price_diff <= 25000:
            score += 15
            reasons.append(f"price close (diff Rs.{price_diff:,.0f})")

        return score, " + ".join(reasons) if reasons else "no match"

    def find_matching_laptop(self, full_title, price):
        """
        Finds if a laptop already exists in the DB by scoring similarity
        against all existing laptops using specs + price.

        Returns the matching laptop dict or None if no match found.
        """
        if not self.conn:
            return None

        # Get all existing laptops with their average price from DB
        self.cursor.execute("""
            SELECT l.id, l.name,
                   COALESCE(AVG(p.price), 0) as avg_price
            FROM laptops l
            LEFT JOIN prices p ON p.laptop_id = l.id
            GROUP BY l.id, l.name
        """)
        existing = self.cursor.fetchall()

        if not existing:
            return None

        best_score  = 0
        best_match  = None
        THRESHOLD   = 70  # need 70+ points to be considered same laptop

        for row in existing:
            existing_avg_price = float(row["avg_price"]) if row["avg_price"] else price
            score, reason = self.score_similarity(
                full_title, row["name"], price, existing_avg_price
            )
            if score > best_score:
                best_score = score
                best_match = row
                best_reason = reason

        if best_score >= THRESHOLD:
            print(f"    MATCH ({best_score}pts): '{full_title[:50]}' → '{best_match['name']}' [{best_reason}]")
            return best_match

        print(f"    NO MATCH ({best_score}pts): '{full_title[:50]}' → new group")
        return None

    def save_to_db(self, products):
        if not products:
            print(f"\n  No products to save for {self.retailer}")
            return

        if not self.conn:
            print(f"\n  Database not connected - skipping save for {self.retailer}")
            return

        print(f"\n{'='*60}")
        print(f" Saving {len(products)} products from {self.retailer} to database...")
        print(f"{'='*60}\n")

        saved = 0
        for product in products:
            try:
                full_title = product["name"]
                price      = product["price"]
                brand      = product["brand"]
                base_model = self.extract_base_model(full_title)

                print(f"\n Processing: {full_title[:60]}...")

                # Step 1 — check exact name match first (fast)
                self.cursor.execute(
                    "SELECT id FROM laptops WHERE name = %s",
                    (base_model,)
                )
                existing = self.cursor.fetchone()

                if existing:
                    laptop_id = existing["id"]
                    print(f"  Exact match: '{base_model}'")

                else:
                    # Step 2 — score against all existing laptops using full title + price
                    match = self.find_matching_laptop(full_title, price)

                    if match:
                        laptop_id = match["id"]
                        print(f"  Scored match → using group '{match['name']}'")
                    else:
                        # Step 3 — genuinely new laptop, create new group
                        self.cursor.execute(
                            """INSERT INTO laptops (name, brand, image_url, specs)
                               VALUES (%s, %s, %s, %s)""",
                            (base_model, brand, product["image"], "Auto-scraped")
                        )
                        laptop_id = self.cursor.lastrowid
                        print(f"  New group created: '{base_model}'")

                # Save the price entry
                self.cursor.execute(
                    """SELECT id FROM prices
                       WHERE laptop_id = %s
                         AND retailer = %s
                         AND DATE(scraped_at) = CURDATE()""",
                    (laptop_id, product["retailer"])
                )
                already_saved = self.cursor.fetchone()

                if already_saved:
                    self.cursor.execute(
                        """UPDATE prices SET price = %s, product_url = %s, scraped_at = %s
                           WHERE id = %s""",
                        (price, product["url"], datetime.now(), already_saved["id"])
                    )
                    print(f"  Updated price: {product['retailer']} Rs.{price:,.0f}")
                else:
                    self.cursor.execute(
                        """INSERT INTO prices (laptop_id, retailer, price, product_url, scraped_at)
                           VALUES (%s, %s, %s, %s, %s)""",
                        (laptop_id, product["retailer"], price,
                         product["url"], datetime.now())
                    )
                    print(f"  New price: {product['retailer']} Rs.{price:,.0f}")

                saved += 1
                self.conn.commit()

            except Exception as e:
                print(f"  Error saving '{product.get('name', '?')}': {e}")
                if self.conn:
                    self.conn.rollback()

        print(f"\n Successfully saved {saved} price entries from {self.retailer}")

    def update_existing_products(self, scraped_urls):
        """
        After the main scrape, find any products from this retailer
        already in the DB whose URL was NOT seen in this run.
        Visit each stored URL directly and update the price.

        scraped_urls: set of product URLs collected during the main scrape
        """
        if not self.conn or not self.driver:
            return

        print(f"\n{'='*60}")
        print(f" Checking for existing {self.retailer} products not in top {PER_SITE_MAX_RESULTS}...")
        print(f"{'='*60}\n")

        # Get all distinct URLs for this retailer saved in the DB
        self.cursor.execute(
            """SELECT DISTINCT product_url, laptop_id
               FROM prices
               WHERE retailer = %s
                 AND product_url IS NOT NULL""",
            (self.retailer,)
        )
        all_db_urls = self.cursor.fetchall()

        # Filter out ones we already updated in this run
        missed = [row for row in all_db_urls if row["product_url"] not in scraped_urls]

        if not missed:
            print(f"  All existing products were covered in the main scrape. Nothing to update.")
            return

        print(f"  Found {len(missed)} existing product(s) not seen in this run — updating now...\n")

        updated = 0
        for row in missed:
            url = row["product_url"]
            laptop_id = row["laptop_id"]

            try:
                print(f"  Visiting: {url[:80]}...")
                self.driver.get(url)
                time.sleep(3)

                soup = BeautifulSoup(self.driver.page_source, 'html.parser')

                # Generic price extraction — tries patterns used across all 5 sites
                price = None

                # Pattern 1: span.price  (Mudita / Nagmani — Magento)
                price_elem = soup.find("span", class_="price")
                if price_elem:
                    price = self.clean_price(price_elem.get_text(strip=True))

                # Pattern 2: span fs-4 fw-bold  (Onin)
                if not price:
                    price_elem = soup.find("span", class_=lambda x: x and "fs-4" in x and "fw-bold" in x)
                    if price_elem:
                        price = self.clean_price(price_elem.get_text(strip=True))

                # Pattern 3: woocommerce-Price-amount  (Yantra Nepal)
                if not price:
                    price_elem = soup.find("span", class_="woocommerce-Price-amount")
                    if price_elem:
                        bdi = price_elem.find("bdi")
                        price = self.clean_price((bdi or price_elem).get_text(strip=True))

                # Pattern 4: Daraz ooOxS span
                if not price:
                    price_elem = soup.find("span", class_="ooOxS")
                    if price_elem:
                        price = self.clean_price(price_elem.get_text(strip=True))

                # Pattern 5: fallback — any Rs / रू anywhere on page
                if not price:
                    text = soup.get_text()
                    m = re.search(r'(?:Rs\.?\s*|रू\.?)\s*([\d,]+)', text)
                    if m:
                        price = self.clean_price(m.group(0))

                if not price or price == 0:
                    print(f"    Could not extract price — skipping\n")
                    continue

                print(f"    Price found: Rs.{price:,.0f}")

                # Check if already updated today for this exact URL
                self.cursor.execute(
                    """SELECT id FROM prices
                       WHERE laptop_id = %s
                         AND retailer = %s
                         AND product_url = %s
                         AND DATE(scraped_at) = CURDATE()""",
                    (laptop_id, self.retailer, url)
                )
                existing_today = self.cursor.fetchone()

                if existing_today:
                    self.cursor.execute(
                        """UPDATE prices SET price = %s, scraped_at = %s
                           WHERE id = %s""",
                        (price, datetime.now(), existing_today["id"])
                    )
                    print(f"    Updated today's record.\n")
                else:
                    self.cursor.execute(
                        """INSERT INTO prices (laptop_id, retailer, price, product_url, scraped_at)
                           VALUES (%s, %s, %s, %s, %s)""",
                        (laptop_id, self.retailer, price, url, datetime.now())
                    )
                    print(f"    Inserted new price record.\n")

                self.conn.commit()
                updated += 1

            except Exception as e:
                print(f"    Error updating {url}: {e}\n")
                if self.conn:
                    self.conn.rollback()
                continue

        print(f"  Done — updated {updated} existing product(s) for {self.retailer}\n")

    def close(self):
        print(f"\n Cleaning up {self.retailer} scraper...")
        if self.driver:
            self.driver.quit()
        if self.cursor:
            self.cursor.close()
        if self.conn:
            self.conn.close()
        print(" Done!\n")


class MuditaScraper(BaseScraper):
    def __init__(self):
        super().__init__("Mudita")

    def scrape_mudita(self, keyword="laptop", max_results=25, max_pages=3, start_page=2):
        print(f"\n{'='*60}")
        print(f" Scraping Mudita for: {keyword} (from page {start_page})")
        print(f"{'='*60}\n")

        all_products = []

        for page in range(start_page, start_page + max_pages):
            print(f" Scraping page {page}...")
            url = f"https://mudita.com.np/catalogsearch/result/?q={keyword.replace(' ', '+')}&p={page}"

            try:
                self.driver.get(url)
                print(f" Loading page {page}...")
                time.sleep(3)

                print(" Scrolling to load items...")
                for i in range(3):
                    self.driver.execute_script(f"window.scrollTo(0, {(i+1)*1000});")
                    time.sleep(1)

                soup = BeautifulSoup(self.driver.page_source, 'html.parser')

                if page == start_page:
                    with open("mudita_page.html", "w", encoding="utf-8") as f:
                        f.write(soup.prettify())
                    print(" Page saved to mudita_page.html\n")

                products = soup.find_all("div", class_="product-item-info")
                if not products:
                    products = soup.find_all("div", class_="product-item")
                if not products:
                    products = soup.find_all("li", class_="item product product-item")

                print(f" Found {len(products)} product containers on page {page}")

                if not products:
                    print(f" No more products found on page {page}, stopping pagination")
                    break

                for idx, product in enumerate(products, 1):
                    if len(all_products) >= max_results:
                        break

                    try:
                        print(f"Processing item {len(all_products) + 1}...")

                        # TITLE
                        title = None
                        title_elem = product.find("a", class_="product-item-link")
                        if title_elem:
                            title = title_elem.get_text(strip=True)

                        if not title:
                            h2_tag = product.find("h2", class_="product-name")
                            if h2_tag:
                                title = h2_tag.get_text(strip=True)

                        if not title or len(title) < 5:
                            print(f" Skipped - No valid title\n")
                            continue

                        print(f"  Title: {title[:60]}...")

                        # PRICE
                        price = None
                        price_elem = product.find("span", class_="price")
                        if price_elem:
                            price = self.clean_price(price_elem.get_text(strip=True))

                        if not price:
                            price_elem = product.find("span", class_="normal-price")
                            if price_elem:
                                price = self.clean_price(price_elem.get_text(strip=True))

                        if not price:
                            price_container = product.find("div", class_="price-box")
                            if price_container:
                                price_elem = price_container.find("span", class_="price")
                                if price_elem:
                                    price = self.clean_price(price_elem.get_text(strip=True))

                        if not price or price == 0:
                            print(f" Skipped - No valid price\n")
                            continue

                        print(f"  Price: Rs.{price:,.2f}")

                        # LINK
                        link = None
                        if title_elem and "href" in title_elem.attrs:
                            link = title_elem["href"]

                        if not link:
                            link_elem = product.find("a", class_="product-item-link")
                            if link_elem and "href" in link_elem.attrs:
                                link = link_elem["href"]

                        if not link:
                            print(f"  Skipped - No link\n")
                            continue

                        # IMAGE
                        image_url = None
                        img_elem = product.find("img", class_="product-image-photo")
                        if img_elem and "src" in img_elem.attrs:
                            image_url = img_elem["src"]

                        if not image_url:
                            img_elem = product.find("img")
                            if img_elem and "src" in img_elem.attrs:
                                image_url = img_elem["src"]

                        brand = self.extract_brand(title)

                        all_products.append({
                            "name": title[:255],
                            "brand": brand,
                            "price": price,
                            "url": link,
                            "image": image_url,
                            "retailer": "Mudita"
                        })

                        print(f"Added to results\n")

                    except Exception as e:
                        print(f" Error: {e}\n")
                        continue

                if len(all_products) >= max_results:
                    print(f"Reached max results limit ({max_results}), stopping pagination")
                    break

            except Exception as e:
                print(f"Error scraping page {page}: {e}")
                continue

        print(f"\nTotal products collected: {len(all_products)}")
        return all_products

    def run(self):
        if not self.driver:
            print(f"\nSkipping {self.retailer} scraper - driver not available")
            return

        try:
            products = self.scrape_mudita("laptop", max_results=PER_SITE_MAX_RESULTS, max_pages=3, start_page=MUDITA_START_PAGE)

            print(f"\n{'='*60}")
            print(f" MUDITA SCRAPING RESULTS")
            print(f"{'='*60}")
            print(f"Total products found: {len(products)}")

            if products:
                print(f"\nSample products:")
                for i, p in enumerate(products[:3], 1):
                    print(f"\n{i}. {p['name'][:60]}...")
                    print(f"   Brand: {p['brand']}")
                    print(f"   Price: Rs.{p['price']:,.2f}")

                super().save_to_db(products)
                super().update_existing_products({p["url"] for p in products})
            else:
                print("\n  No products were scraped successfully")
                super().update_existing_products(set())

        except Exception as e:
            print(f"\n Fatal error: {e}")
        finally:
            super().close()


class DarazScraper(BaseScraper):
    def __init__(self):
        super().__init__("Daraz")

    def scrape_daraz(self, keyword="laptop", max_results=20):
        print(f"\n{'='*60}")
        print(f" Scraping Daraz for: {keyword}")
        print(f"{'='*60}\n")

        url = f"https://www.daraz.com.np/catalog/?spm=a2a0e.tm80335409.search.d_go&q={keyword.replace(' ', '+')}"

        try:
            self.driver.get(url)
            print(" Loading page...")
            time.sleep(4)

            print(" Scrolling to load items...")
            for i in range(4):
                self.driver.execute_script(f"window.scrollTo(0, {(i+1)*1000});")
                time.sleep(2)

            soup = BeautifulSoup(self.driver.page_source, 'html.parser')

            with open("daraz_page.html", "w", encoding="utf-8") as f:
                f.write(soup.prettify())
            print(" Page saved to daraz_page.html\n")

            products = soup.find_all("div", class_="Bm3ON")
            if not products:
                products = soup.find_all("div", {"data-item-id": True})
            if not products:
                products = soup.find_all("div", class_="item-card")
            if not products:
                products = soup.find_all("div", {"data-qa-locator": "product-item"})

            print(f" Found {len(products)} product containers\n")

            results = []

            for idx, product in enumerate(products[:max_results], 1):
                try:
                    print(f"Processing item {idx}...")

                    # TITLE
                    title = None
                    all_links = product.find_all("a")
                    for link in all_links:
                        if "title" in link.attrs and link.get_text(strip=True):
                            title = link.get_text(strip=True)
                            break

                    if not title:
                        title_elem = product.find("div", class_="RfADt")
                        if title_elem:
                            a_tag = title_elem.find("a")
                            if a_tag:
                                title = a_tag.get_text(strip=True)

                    if not title:
                        divs_with_links = product.find_all("div")
                        for div in divs_with_links:
                            a_tag = div.find("a")
                            if a_tag and a_tag.get_text(strip=True):
                                title = a_tag.get_text(strip=True)
                                break

                    if not title or len(title) < 5:
                        print(f" Skipped - No valid title\n")
                        continue

                    print(f"  Title: {title[:60]}...")

                    # PRICE
                    price = None
                    price_elem = product.find("div", class_="aBrP0")
                    if price_elem:
                        price_span = price_elem.find("span", class_="ooOxS")
                        if price_span:
                            price_text = price_span.get_text(strip=True)
                            price = self.clean_price(price_text)

                    if not price:
                        price_elem = product.find("span", class_="currency--GVKjl")
                        if price_elem:
                            price_parent = price_elem.parent
                            if price_parent:
                                price = self.clean_price(price_parent.get_text(strip=True))

                    if not price:
                        price_elem = product.find("span", string=re.compile(r'Rs\.'))
                        if price_elem:
                            price = self.clean_price(price_elem.get_text(strip=True))

                    if not price or price == 0:
                        print(f" Skipped - No valid price\n")
                        continue

                    print(f"  Price: Rs.{price:,.2f}")

                    # LINK
                    link = None
                    link_elem = product.find("div", class_="RfADt")
                    if link_elem:
                        a_tag = link_elem.find("a")
                        if a_tag and "href" in a_tag.attrs:
                            href = a_tag["href"]
                            link = f"https:{href}" if href.startswith("//") else href

                    if not link:
                        for a_tag in all_links:
                            if "href" in a_tag.attrs:
                                href = a_tag["href"]
                                if "daraz.com" in href or href.startswith("//"):
                                    link = f"https:{href}" if href.startswith("//") else href
                                    break

                    if not link:
                        print(f"  Skipped - No link\n")
                        continue

                    # IMAGE
                    image_url = None
                    img_elem = product.find("div", class_="picture-wrapper")
                    if img_elem:
                        img_tag = img_elem.find("img")
                        if img_tag and "src" in img_tag.attrs:
                            image_url = img_tag["src"]

                    if not image_url:
                        img_elem = product.find("img")
                        if img_elem and "src" in img_elem.attrs:
                            image_url = img_elem["src"]

                    brand = self.extract_brand(title)

                    results.append({
                        "name": title[:255],
                        "brand": brand,
                        "price": price,
                        "url": link,
                        "image": image_url,
                        "retailer": "Daraz"
                    })

                    print(f"Added to results\n")

                except Exception as e:
                    print(f" Error: {e}\n")
                    continue

            return results

        except Exception as e:
            print(f" Failed to scrape Daraz: {e}")
            return []

    def run(self):
        if not self.driver:
            print(f"\nSkipping {self.retailer} scraper - driver not available")
            return

        try:
            products = self.scrape_daraz("laptop", max_results=PER_SITE_MAX_RESULTS)

            print(f"\n{'='*60}")
            print(f" DARAZ SCRAPING RESULTS")
            print(f"{'='*60}")
            print(f"Total products found: {len(products)}")

            if products:
                print(f"\nSample products:")
                for i, p in enumerate(products[:3], 1):
                    print(f"\n{i}. {p['name'][:60]}...")
                    print(f"   Brand: {p['brand']}")
                    print(f"   Price: Rs.{p['price']:,.2f}")

                super().save_to_db(products)
                super().update_existing_products({p["url"] for p in products})
            else:
                print("\n  No products were scraped successfully")
                super().update_existing_products(set())

        except Exception as e:
            print(f"\n Fatal error: {e}")
        finally:
            super().close()


class NagmaniScraper(BaseScraper):
    def __init__(self):
        super().__init__("Nagmani")

    def scrape_nagmani(self, keyword="laptop", max_results=20):
        print(f"\n{'='*60}")
        print(f" Scraping Nagmani for: {keyword}")
        print(f"{'='*60}\n")

        url = "https://nagmani.com.np/laptops-pc"

        try:
            self.driver.get(url)
            print(" Loading page...")
            time.sleep(3)

            print(" Scrolling to load items...")
            for i in range(3):
                self.driver.execute_script(f"window.scrollTo(0, {(i+1)*1000});")
                time.sleep(1)

            soup = BeautifulSoup(self.driver.page_source, 'html.parser')

            with open("nagmani_page.html", "w", encoding="utf-8") as f:
                f.write(soup.prettify())
            print(" Page saved to nagmani_page.html\n")

            products = soup.find_all("li", class_="item product product-item")
            if not products:
                products = soup.find_all("div", class_="product-item")
            if not products:
                products = soup.find_all("div", class_="product")

            print(f" Found {len(products)} product containers\n")

            results = []

            for idx, product in enumerate(products[:max_results], 1):
                try:
                    print(f"Processing item {idx}...")

                    # TITLE
                    title = None
                    title_elem = product.find("strong", class_="product name product-item-name")
                    if title_elem:
                        a_tag = title_elem.find("a", class_="product-item-link")
                        if a_tag:
                            title = a_tag.get_text(strip=True)

                    if not title:
                        title_elem = product.find("a", class_="product-item-link")
                        if title_elem:
                            title = title_elem.get_text(strip=True)

                    if not title or len(title) < 5:
                        print(f" Skipped - No valid title\n")
                        continue

                    print(f"  Title: {title[:60]}...")

                    # PRICE
                    price = None
                    price_elem = product.find("span", class_="price")
                    if price_elem:
                        price_text = price_elem.get_text(strip=True)
                        price = self.clean_price(price_text)

                    if not price:
                        price_box = product.find("div", class_="price-box price-final_price")
                        if price_box:
                            price_elem = price_box.find("span", class_="price")
                            if price_elem:
                                price = self.clean_price(price_elem.get_text(strip=True))

                    if not price or price == 0:
                        print(f" Skipped - No valid price\n")
                        continue

                    print(f"  Price: Rs.{price:,.2f}")

                    # LINK
                    link = None
                    link_elem = product.find("a", class_="product-item-link")
                    if link_elem and "href" in link_elem.attrs:
                        link = link_elem["href"]

                    if not link:
                        link_elem = product.find("a", class_="product-link")
                        if link_elem and "href" in link_elem.attrs:
                            href = link_elem["href"]
                            link = f"https://nagmani.com.np{href}" if href.startswith("/") else href

                    if not link:
                        print(f"  Skipped - No link\n")
                        continue

                    # IMAGE
                    image_url = None
                    img_elem = product.find("img", class_="product-image-photo")
                    if img_elem and "src" in img_elem.attrs:
                        image_url = img_elem["src"]

                    if not image_url:
                        photo_link = product.find("a", class_="product photo product-item-photo")
                        if photo_link:
                            img_elem = photo_link.find("img")
                            if img_elem and "src" in img_elem.attrs:
                                image_url = img_elem["src"]

                    if not image_url:
                        img_elem = product.find("img")
                        if img_elem and "src" in img_elem.attrs:
                            image_url = img_elem["src"]

                    brand = self.extract_brand(title)

                    results.append({
                        "name": title[:255],
                        "brand": brand,
                        "price": price,
                        "url": link,
                        "image": image_url,
                        "retailer": "Nagmani"
                    })

                    print(f"Added to results\n")

                except Exception as e:
                    print(f" Error: {e}\n")
                    continue

            return results

        except Exception as e:
            print(f" Failed to scrape Nagmani: {e}")
            return []

    def run(self):
        if not self.driver:
            print(f"\nSkipping {self.retailer} scraper - driver not available")
            return

        try:
            products = self.scrape_nagmani("laptop", max_results=PER_SITE_MAX_RESULTS)

            print(f"\n{'='*60}")
            print(f" NAGMANI SCRAPING RESULTS")
            print(f"{'='*60}")
            print(f"Total products found: {len(products)}")

            if products:
                print(f"\nSample products:")
                for i, p in enumerate(products[:3], 1):
                    print(f"\n{i}. {p['name'][:60]}...")
                    print(f"   Brand: {p['brand']}")
                    print(f"   Price: Rs.{p['price']:,.2f}")

                super().save_to_db(products)
                super().update_existing_products({p["url"] for p in products})
            else:
                print("\n  No products were scraped successfully")
                super().update_existing_products(set())

        except Exception as e:
            print(f"\n Fatal error: {e}")
        finally:
            super().close()


class YantraNepalScraper(BaseScraper):
    def __init__(self):
        super().__init__("Yantra Nepal")

    def scrape_yantra_nepal(self, keyword="laptop", max_results=20):
        print(f"\n{'='*60}")
        print(f" Scraping Yantra Nepal for: {keyword}")
        print(f"{'='*60}\n")

        url = "https://yantranepal.com/laptop-price-in-nepal/"

        try:
            self.driver.get(url)
            print(" Loading page...")
            time.sleep(4)

            print(" Scrolling to load items...")
            for i in range(4):
                self.driver.execute_script(f"window.scrollTo(0, {(i+1)*1000});")
                time.sleep(2)

            soup = BeautifulSoup(self.driver.page_source, 'html.parser')

            with open("yantra_nepal_page.html", "w", encoding="utf-8") as f:
                f.write(soup.prettify())
            print(" Page saved to yantra_nepal_page.html\n")

            products = soup.find_all("div", class_=lambda x: x and "e-loop-item" in x and "product" in x)
            if not products:
                products = soup.find_all("div", class_="product")
            if not products:
                products = soup.find_all("article", class_="product")
            if not products:
                products = soup.find_all("li", class_="product")

            print(f" Found {len(products)} product containers\n")

            results = []

            for idx, product in enumerate(products[:max_results], 1):
                try:
                    print(f"Processing item {idx}...")

                    # TITLE
                    title = None
                    title_elem = product.find("h2", class_="product_title")
                    if title_elem:
                        a_tag = title_elem.find("a")
                        if a_tag:
                            title = a_tag.get_text(strip=True)

                    if not title:
                        title_elem = product.find("h2", class_="woocommerce-loop-product__title")
                        if title_elem:
                            title = title_elem.get_text(strip=True)

                    if not title:
                        title_elem = product.find("a", href=lambda x: x and "yantranepal.com" in x)
                        if title_elem:
                            title = title_elem.get_text(strip=True)

                    if not title or len(title) < 5:
                        print(f" Skipped - No valid title\n")
                        continue

                    print(f"  Title: {title[:60]}...")

                    # PRICE
                    price = None
                    price_elem = product.find("p", class_="price")
                    if price_elem:
                        amount_elem = price_elem.find("span", class_="woocommerce-Price-amount")
                        if amount_elem:
                            bdi_elem = amount_elem.find("bdi")
                            if bdi_elem:
                                price_text = bdi_elem.get_text(strip=True)
                                price = self.clean_price(price_text)

                    if not price:
                        amount_elem = product.find("span", class_="woocommerce-Price-amount")
                        if amount_elem:
                            bdi_elem = amount_elem.find("bdi")
                            if bdi_elem:
                                price_text = bdi_elem.get_text(strip=True)
                                price = self.clean_price(price_text)

                    if not price:
                        all_text = product.get_text()
                        price_match = re.search(r'Rs\.?\s*([\d,]+\.?\d*)', all_text)
                        if price_match:
                            price = self.clean_price(price_match.group(1))

                    if not price or price == 0:
                        print(f" Skipped - No valid price\n")
                        continue

                    print(f"  Price: Rs.{price:,.2f}")

                    # LINK
                    link = None
                    title_elem = product.find("h2", class_="product_title")
                    if title_elem:
                        link_elem = title_elem.find("a")
                        if link_elem and "href" in link_elem.attrs:
                            link = link_elem["href"]

                    if not link:
                        title_elem = product.find("h2", class_="woocommerce-loop-product__title")
                        if title_elem:
                            link_elem = title_elem.find("a")
                            if link_elem and "href" in link_elem.attrs:
                                link = link_elem["href"]

                    if not link:
                        link_elem = product.find("a", href=lambda x: x and "yantranepal.com" in x)
                        if link_elem and "href" in link_elem.attrs:
                            link = link_elem["href"]

                    if not link:
                        print(f"  Skipped - No link\n")
                        continue

                    # IMAGE
                    image_url = None
                    img_elem = product.find("img", class_="attachment-woocommerce_thumbnail")
                    if img_elem and "src" in img_elem.attrs:
                        image_url = img_elem["src"]

                    if not image_url:
                        img_elem = product.find("img")
                        if img_elem and "src" in img_elem.attrs:
                            image_url = img_elem["src"]

                    brand = self.extract_brand(title)

                    results.append({
                        "name": title[:255],
                        "brand": brand,
                        "price": price,
                        "url": link,
                        "image": image_url,
                        "retailer": "Yantra Nepal"
                    })

                    print(f"Added to results\n")

                except Exception as e:
                    print(f" Error: {e}\n")
                    continue

            return results

        except Exception as e:
            print(f" Failed to scrape Yantra Nepal: {e}")
            return []

    def run(self):
        if not self.driver:
            print(f"\nSkipping {self.retailer} scraper - driver not available")
            return

        try:
            products = self.scrape_yantra_nepal("laptop", max_results=PER_SITE_MAX_RESULTS)

            print(f"\n{'='*60}")
            print(f" YANTRA NEPAL SCRAPING RESULTS")
            print(f"{'='*60}")
            print(f"Total products found: {len(products)}")

            if products:
                print(f"\nSample products:")
                for i, p in enumerate(products[:3], 1):
                    print(f"\n{i}. {p['name'][:60]}...")
                    print(f"   Brand: {p['brand']}")
                    print(f"   Price: Rs.{p['price']:,.2f}")

                super().save_to_db(products)
                super().update_existing_products({p["url"] for p in products})
            else:
                print("\n  No products were scraped successfully")
                super().update_existing_products(set())

        except Exception as e:
            print(f"\n Fatal error: {e}")
        finally:
            super().close()


class OninScraper(BaseScraper):
    def __init__(self):
        super().__init__("Onin")

    def scrape_onin(self, max_results=30):
        print(f"\n{'='*60}")
        print(f" Scraping Onin for: laptops")
        print(f"{'='*60}\n")

        all_products = []
        page = 1

        while len(all_products) < max_results:
            url = f"https://onin.com.np/products?search=laptop&page={page}"

            try:
                self.driver.get(url)
                print(f" Loading page {page}...")
                time.sleep(3)

                print(" Scrolling to load items...")
                for i in range(3):
                    self.driver.execute_script(f"window.scrollTo(0, {(i+1)*1000});")
                    time.sleep(1)

                soup = BeautifulSoup(self.driver.page_source, 'html.parser')

                if page == 1:
                    with open("onin_page.html", "w", encoding="utf-8") as f:
                        f.write(soup.prettify())
                    print(" Page saved to onin_page.html\n")

                # Each product is inside a Bootstrap col-6 col-lg-3 grid column
                cards = soup.find_all("div", class_=lambda x: x and "col-6" in x and "col-lg-3" in x)
                print(f" Found {len(cards)} product cards on page {page}")

                if not cards:
                    print(f" No cards found on page {page}, stopping.")
                    break

                for card in cards:
                    if len(all_products) >= max_results:
                        break

                    try:
                        print(f"Processing item {len(all_products) + 1}...")

                        # TITLE — from <h6 class="fw-semibold"> inside card-body
                        h6 = card.find("h6", class_="fw-semibold")
                        if not h6:
                            continue
                        a_tag = h6.find("a")
                        if not a_tag:
                            continue
                        title = a_tag.get_text(strip=True).rstrip(".")
                        if not title or len(title) < 5:
                            print(f"  Skipped - No valid title\n")
                            continue

                        print(f"  Title: {title[:60]}...")

                        # PRICE — <span class="fs-4 fw-bold text-dark"> (skip strikethrough old price)
                        price = None
                        price_elem = card.find("span", class_=lambda x: x and "fs-4" in x and "fw-bold" in x)
                        if price_elem:
                            price = self.clean_price(price_elem.get_text(strip=True))

                        if not price or price == 0:
                            print(f"  Skipped - No valid price\n")
                            continue

                        print(f"  Price: Rs.{price:,.2f}")

                        # LINK — from the <a> tag in the h6
                        link = a_tag.get("href", "")
                        if not link:
                            print(f"  Skipped - No link\n")
                            continue

                        # IMAGE — from the ratio div above card-body
                        image_url = None
                        img = card.find("img")
                        if img:
                            image_url = img.get("src") or img.get("data-src")

                        brand = self.extract_brand(title)

                        all_products.append({
                            "name":     title[:255],
                            "brand":    brand,
                            "price":    price,
                            "url":      link,
                            "image":    image_url,
                            "retailer": "Onin"
                        })

                        print(f"  Added!\n")

                    except Exception as e:
                        print(f"  Error on item: {e}\n")
                        continue

                # Check if there is a next page
                next_link = soup.find("a", rel="next")
                if not next_link:
                    print(f" No next page found, stopping pagination.")
                    break

                page += 1

            except Exception as e:
                print(f" Failed on page {page}: {e}")
                break

        print(f"\nTotal products collected: {len(all_products)}")
        return all_products

    def run(self):
        if not self.driver:
            print(f"\nSkipping {self.retailer} scraper - driver not available")
            return

        try:
            products = self.scrape_onin(max_results=PER_SITE_MAX_RESULTS)

            print(f"\n{'='*60}")
            print(f" ONIN SCRAPING RESULTS")
            print(f"{'='*60}")
            print(f"Total products found: {len(products)}")

            if products:
                print(f"\nSample products:")
                for i, p in enumerate(products[:3], 1):
                    print(f"\n{i}. {p['name'][:60]}...")
                    print(f"   Brand: {p['brand']}")
                    print(f"   Price: Rs.{p['price']:,.2f}")

                super().save_to_db(products)
                super().update_existing_products({p["url"] for p in products})
            else:
                print("\n  No products were scraped successfully")
                super().update_existing_products(set())

        except Exception as e:
            print(f"\n Fatal error: {e}")
        finally:
            super().close()


if __name__ == "__main__":
    print(" Starting laptop scraping...")

    print("\n" + "="*80)
    print("STARTING MUDITA SCRAPER")
    print("="*80)
    mudita_scraper = MuditaScraper()
    mudita_scraper.run()

    print("\n" + "="*80)
    print("STARTING DARAZ SCRAPER")
    print("="*80)
    daraz_scraper = DarazScraper()
    daraz_scraper.run()

    print("\n" + "="*80)
    print("STARTING NAGMANI SCRAPER")
    print("="*80)
    nagmani_scraper = NagmaniScraper()
    nagmani_scraper.run()

    print("\n" + "="*80)
    print("STARTING YANTRA NEPAL SCRAPER")
    print("="*80)
    yantra_nepal_scraper = YantraNepalScraper()
    yantra_nepal_scraper.run()

    print("\n" + "="*80)
    print("STARTING ONIN SCRAPER")
    print("="*80)
    onin_scraper = OninScraper()
    onin_scraper.run()

    print("\n" + "="*80)
    print("ALL SCRAPERS COMPLETED")
    print("="*80)
    print("\nActive retailers: 5 (Mudita, Daraz, Nagmani, Yantra Nepal, Onin)")