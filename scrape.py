#!/usr/bin/env python3
"""
Multi-Website Laptop Scraper - Mudita, Daraz, Nagmani, Maxell, Yantra Nepal, Hukut, Neo Store, Onin, Computer Durbar
"""

import time
import re
from datetime import datetime
from bs4 import BeautifulSoup
import mysql.connector

from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.common.by import By
from webdriver_manager.chrome import ChromeDriverManager

DB_CONFIG = {
    "host": "localhost",
    "user": "root",
    "password": "",
    "database": "laptop_comparison",
}

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
            pass  # Ignore if script fails

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
        # Extract numeric values, handling commas and currency symbols
        # Look for patterns like "Rs. 40,500", "₹40,500", "रू.40,500" or just "40,500"
        import re
        # First, try to find a price pattern with Rs., ₹, or रू.
        price_match = re.search(r'(?:Rs\.?\s*|₹|रू\.?)\s*([\d,]+(?:\.\d+)?)', price_text.strip())
        if price_match:
            numeric_part = price_match.group(1).replace(',', '')
            try:
                return float(numeric_part)
            except:
                pass

        # Fallback: remove all non-numeric characters except decimal point
        cleaned = re.sub(r'[^\d.]', '', price_text)
        try:
            return float(cleaned)
        except:
            return None

    def extract_brand(self, name):
        brands = ["Dell", "HP", "Lenovo", "ASUS", "Acer", "Apple",
                  "MSI", "Samsung", "Microsoft", "Razer", "LG"]
        name_lower = name.lower()
        for brand in brands:
            if brand.lower() in name_lower:
                return brand
        return "Other"

    def extract_base_model(self, name):
        """
        Extract the base model name for grouping laptops.
        Examples:
        - "ASUS VivoBook 14 X415EA" -> "ASUS VivoBook 14"
        - "Lenovo LOQ 15ARP9 Ryzen 5" -> "Lenovo LOQ 15"
        - "Dell Latitude 5320" -> "Dell Latitude 5320"
        """
        # First, get the brand
        brand = self.extract_brand(name)
        if brand == "Other":
            return name.split()[0] if name.split() else name

        # Remove the brand from the name for processing
        name_without_brand = name.replace(brand, "", 1).strip()

        # Split into words
        words = name_without_brand.split()

        if not words:
            return brand

        # Build the base model name
        base_model = brand

        # Add model series (usually the first 1-3 words after brand)
        for i, word in enumerate(words):
            # Stop at version numbers, processors, or specifications
            if any(skip in word.lower() for skip in [
                'ryzen', 'intel', 'core', 'i3', 'i5', 'i7', 'i9',
                'ram', 'gb', 'ssd', 'hdd', 'display', 'inch',
                'gaming', 'laptop', 'notebook', 'series'
            ]):
                break

            # Stop at 4-digit numbers (like years: 2024, 2025)
            if len(word) == 4 and word.isdigit():
                break

            # Add the word if it's not too long and not a spec
            if len(word) <= 20 and not word.isdigit():
                base_model += " " + word

            # Limit to 3 words after brand to avoid over-grouping
            if i >= 2:
                break

        return base_model.strip()

    def filter_by_brand(self, products, brands):
        """
        Filter products by brand(s)
        brands: list of brand names (case insensitive)
        """
        if not brands:
            return products

        filtered = []
        brand_lower = [b.lower() for b in brands]

        for product in products:
            product_brand = self.extract_brand(product["name"]).lower()
            if product_brand in brand_lower:
                filtered.append(product)

        return filtered

    def search_products(self, products, query):
        """
        Search products by model name or keywords
        query: search string (case insensitive)
        """
        if not query:
            return products

        query_lower = query.lower()
        filtered = []

        for product in products:
            name_lower = product["name"].lower()
            base_model_lower = self.extract_base_model(product["name"]).lower()

            # Search in full name, base model, or brand
            if (query_lower in name_lower or
                query_lower in base_model_lower or
                query_lower in product["brand"].lower()):
                filtered.append(product)

        return filtered

    def get_products_from_db(self, brand_filter=None, search_query=None):
        """
        Retrieve products from database with optional filtering
        Returns grouped products with prices from all retailers
        """
        if not self.conn:
            print("Database not connected")
            return []

        try:
            # Base query to get laptops with their prices
            query = """
                SELECT
                    l.id,
                    l.name as model_name,
                    l.brand,
                    l.image_url,
                    p.retailer,
                    p.price,
                    p.product_url,
                    p.scraped_at,
                    COUNT(*) OVER (PARTITION BY l.id) as retailer_count
                FROM laptops l
                JOIN prices p ON l.id = p.laptop_id
                WHERE 1=1
            """

            params = []

            # Add brand filter
            if brand_filter:
                query += " AND l.brand = %s"
                params.append(brand_filter)

            # Add search filter
            if search_query:
                query += """ AND (
                    LOWER(l.name) LIKE LOWER(%s) OR
                    LOWER(l.brand) LIKE LOWER(%s)
                )"""
                search_param = f"%{search_query}%"
                params.extend([search_param, search_param])

            # Order by model name and retailer
            query += " ORDER BY l.name, p.retailer"

            self.cursor.execute(query, params)
            rows = self.cursor.fetchall()

            # Group by model
            grouped_products = {}
            for row in rows:
                model_name = row["model_name"]
                if model_name not in grouped_products:
                    grouped_products[model_name] = {
                        "model": model_name,
                        "brand": row["brand"],
                        "image": row["image_url"],
                        "retailers": {},
                        "min_price": float('inf'),
                        "max_price": 0,
                        "retailer_count": row["retailer_count"]
                    }

                grouped_products[model_name]["retailers"][row["retailer"]] = {
                    "price": row["price"],
                    "url": row["product_url"],
                    "scraped_at": row["scraped_at"]
                }

                # Update min/max prices
                price = row["price"]
                grouped_products[model_name]["min_price"] = min(grouped_products[model_name]["min_price"], price)
                grouped_products[model_name]["max_price"] = max(grouped_products[model_name]["max_price"], price)

            # Convert to list and sort by min price
            result = list(grouped_products.values())
            result.sort(key=lambda x: x["min_price"])

            return result

        except Exception as e:
            print(f"Error retrieving products: {e}")
            return []

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

        # Group products by base model
        grouped_products = {}
        for product in products:
            base_model = self.extract_base_model(product["name"])
            if base_model not in grouped_products:
                grouped_products[base_model] = []
            grouped_products[base_model].append(product)

        print(f"Grouped into {len(grouped_products)} model groups")

        saved = 0
        for base_model, product_list in grouped_products.items():
            try:
                # Check if laptop exists by base model
                self.cursor.execute(
                    "SELECT id FROM laptops WHERE name = %s",
                    (base_model,)
                )
                existing = self.cursor.fetchone()

                if existing:
                    laptop_id = existing["id"]
                    print(f"  Existing group: {base_model[:50]}... ({len(product_list)} variants)")
                else:
                    # Insert laptop group - use first product's details
                    first_product = product_list[0]
                    self.cursor.execute(
                        """INSERT INTO laptops (name, brand, image_url, specs)
                           VALUES (%s, %s, %s, %s)""",
                        (base_model, first_product["brand"], first_product["image"], "Auto-scraped")
                    )
                    laptop_id = self.cursor.lastrowid
                    print(f"  New group: {base_model[:50]}... ({len(product_list)} variants)")

                # Insert prices for all products in this group
                for product in product_list:
                    self.cursor.execute(
                        """INSERT INTO prices (laptop_id, retailer, price, product_url, scraped_at)
                           VALUES (%s, %s, %s, %s, %s)""",
                        (laptop_id, product["retailer"], product["price"],
                         product["url"], datetime.now())
                    )
                    saved += 1

                self.conn.commit()

            except Exception as e:
                print(f"  Error saving group {base_model}: {e}")
                if self.conn:
                    self.conn.rollback()

        print(f"\n Successfully saved {saved} price entries from {self.retailer} ({len(grouped_products)} model groups)")

    def close(self):
        print(f"\n Cleaning up {self.retailer} scraper...")
        self.driver.quit()
        if self.cursor:
            self.cursor.close()
        if self.conn:
            self.conn.close()
        print(" Done!\n")

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

            # Scroll to load more items
            print(" Scrolling to load items...")
            for i in range(3):
                self.driver.execute_script(f"window.scrollTo(0, {(i+1)*1000});")
                time.sleep(1)

            soup = BeautifulSoup(self.driver.page_source, 'html.parser')

            # Save for debugging
            with open("nagmani_page.html", "w", encoding="utf-8") as f:
                f.write(soup.prettify())
            print(" Page saved to nagmani_page.html\n")

            # Find all product containers - Nagmani structure (Magento)
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

                    print(f"  Title: {title[:60].encode('ascii', 'ignore').decode('ascii')}...")

                    # PRICE
                    price = None

                    # Try different price selectors for Nagmani (Magento)
                    price_elem = product.find("span", class_="price")
                    if price_elem:
                        price_text = price_elem.get_text(strip=True)
                        price = self.clean_price(price_text)

                    if not price:
                        # Try the price-box structure
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
                        # Try the Magento structure
                        photo_link = product.find("a", class_="product photo product-item-photo")
                        if photo_link:
                            img_elem = photo_link.find("img")
                            if img_elem and "src" in img_elem.attrs:
                                image_url = img_elem["src"]

                    if not image_url:
                        img_elem = product.find("img")
                        if img_elem and "src" in img_elem.attrs:
                            image_url = img_elem["src"]

                    # Extract brand
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
            products = self.scrape_nagmani("laptop", max_results=20)

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
            else:
                print("\n  No products were scraped successfully")
                print(" Check nagmani_page.html to see what was loaded")

        except Exception as e:
            print(f"\n Fatal error: {e}")
        finally:
            super().close()


class MuditaScraper(BaseScraper):
    def __init__(self):
        super().__init__("Mudita")

    def scrape_mudita(self, keyword="laptop", max_results=20, max_pages=3):
        print(f"\n{'='*60}")
        print(f" Scraping Mudita for: {keyword}")
        print(f"{'='*60}\n")

        all_products = []

        for page in range(1, max_pages + 1):
            print(f" Scraping page {page}...")
            url = f"https://mudita.com.np/catalogsearch/result/?q={keyword.replace(' ', '+')}&p={page}"

            try:
                self.driver.get(url)
                print(f" Loading page {page}...")
                time.sleep(3)

                # Scroll to load more items
                print(" Scrolling to load items...")
                for i in range(3):
                    self.driver.execute_script(f"window.scrollTo(0, {(i+1)*1000});")
                    time.sleep(1)

                soup = BeautifulSoup(self.driver.page_source, 'html.parser')

                # Save first page for debugging
                if page == 1:
                    with open("mudita_page.html", "w", encoding="utf-8") as f:
                        f.write(soup.prettify())
                    print(" Page saved to mudita_page.html\n")

                # Find all product containers - Mudita uses different class names
                products = soup.find_all("div", class_="product-item-info")
                if not products:
                    # Try alternative selectors
                    products = soup.find_all("div", class_="product-item")
                if not products:
                    # Try another alternative
                    products = soup.find_all("li", class_="item product product-item")

                print(f" Found {len(products)} product containers on page {page}")

                # If no products found on this page, we've likely reached the end
                if not products:
                    print(f" No more products found on page {page}, stopping pagination")
                    break

                    # Process products from this page
                    for idx, product in enumerate(products, 1):
                        if len(all_products) >= max_results:
                            break

                        try:
                            print(f"Processing item {len(all_products) + idx}...")

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

                            print(f"  Title: {title[:60].encode('ascii', 'ignore').decode('ascii')}...")

                            # PRICE
                            price = None

                            # Try different price selectors for Mudita
                            price_elem = product.find("span", class_="price")
                            if price_elem:
                                price = self.clean_price(price_elem.get_text(strip=True))

                            if not price:
                                price_elem = product.find("span", class_="normal-price")
                                if price_elem:
                                    price = self.clean_price(price_elem.get_text(strip=True))

                            if not price:
                                # Try finding price in a div with price class
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

                            # Extract brand
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

                # Check if we've reached the max results
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
            products = self.scrape_mudita("laptop", max_results=20, max_pages=3)

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
            else:
                print("\n  No products were scraped successfully")
                print(" Check mudita_page.html to see what was loaded")

        except Exception as e:
            print(f"\n Fatal error: {e}")
        finally:
            super().close()

class MaxellScraper(BaseScraper):
    def __init__(self):
        super().__init__("Maxell")

    def scrape_maxell(self, keyword="laptop", max_results=20):
        print(f"\n{'='*60}")
        print(f" Scraping Maxell for: {keyword}")
        print(f"{'='*60}\n")

        url = f"https://maxell.com.np/?s={keyword.replace(' ', '+')}&post_type=product"

        try:
            self.driver.get(url)
            print(" Loading page...")
            time.sleep(3)

            # Scroll to load more items
            print(" Scrolling to load items...")
            for i in range(3):
                self.driver.execute_script(f"window.scrollTo(0, {(i+1)*1000});")
                time.sleep(1)

            soup = BeautifulSoup(self.driver.page_source, 'html.parser')

            # Save for debugging
            with open("maxell_page.html", "w", encoding="utf-8") as f:
                f.write(soup.prettify())
            print(" Page saved to maxell_page.html\n")

            # Find all product containers - Maxell uses custom WooCommerce structure
            products = soup.find_all("article", class_="w-grid-item")
            if not products:
                products = soup.find_all("div", class_="product")
            if not products:
                products = soup.find_all("li", class_="product")

            print(f" Found {len(products)} product containers\n")

            results = []

            for idx, product in enumerate(products[:max_results], 1):
                try:
                    print(f"Processing item {idx}...")

                    # TITLE
                    title = None
                    title_elem = product.find("p", class_="woocommerce-loop-product__title")
                    if title_elem:
                        a_tag = title_elem.find("a")
                        if a_tag:
                            title = a_tag.get_text(strip=True)

                    if not title:
                        title_elem = product.find("h2", class_="woocommerce-loop-product__title")
                        if title_elem:
                            title = title_elem.get_text(strip=True)

                    if not title or len(title) < 5:
                        print(f" Skipped - No valid title\n")
                        continue

                    print(f"  Title: {title[:60].encode('ascii', 'ignore').decode('ascii')}...")

                    # PRICE
                    price = None

                    # Try Maxell's WooCommerce price selectors
                    price_elem = product.find("span", class_="woocommerce-Price-amount")
                    if price_elem:
                        bdi_elem = price_elem.find("bdi")
                        if bdi_elem:
                            price_text = bdi_elem.get_text(strip=True)
                            price = self.clean_price(price_text)

                    if not price:
                        price_elem = product.find("bdi")
                        if price_elem:
                            price = self.clean_price(price_elem.get_text(strip=True))

                    if not price or price == 0:
                        print(f" Skipped - No valid price\n")
                        continue

                    print(f"  Price: Rs.{price:,.2f}")

                    # LINK
                    link = None
                    title_elem = product.find("div", class_="w-post-elm post_title")
                    if title_elem:
                        link_elem = title_elem.find("a")
                        if link_elem and "href" in link_elem.attrs:
                            link = link_elem["href"]

                    if not link:
                        link_elem = product.find("a", class_="woocommerce-LoopProduct-link")
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

                    # Extract brand
                    brand = self.extract_brand(title)

                    results.append({
                        "name": title[:255],
                        "brand": brand,
                        "price": price,
                        "url": link,
                        "image": image_url,
                        "retailer": "Maxell"
                    })

                    print(f"Added to results\n")

                except Exception as e:
                    print(f" Error: {e}\n")
                    continue

            return results

        except Exception as e:
            print(f" Failed to scrape Maxell: {e}")
            return []

    def run(self):
        if not self.driver:
            print(f"\nSkipping {self.retailer} scraper - driver not available")
            return

        try:
            products = self.scrape_maxell("laptop", max_results=20)

            print(f"\n{'='*60}")
            print(f" MAXELL SCRAPING RESULTS")
            print(f"{'='*60}")
            print(f"Total products found: {len(products)}")

            if products:
                print(f"\nSample products:")
                for i, p in enumerate(products[:3], 1):
                    print(f"\n{i}. {p['name'][:60]}...")
                    print(f"   Brand: {p['brand']}")
                    print(f"   Price: Rs.{p['price']:,.2f}")

                super().save_to_db(products)
            else:
                print("\n  No products were scraped successfully")
                print(" Check maxell_page.html to see what was loaded")

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
            time.sleep(3)

            # Scroll to load more items
            print(" Scrolling to load items...")
            for i in range(3):
                self.driver.execute_script(f"window.scrollTo(0, {(i+1)*1000});")
                time.sleep(1)

            soup = BeautifulSoup(self.driver.page_source, 'html.parser')

            # Save for debugging
            with open("yantra_nepal_page.html", "w", encoding="utf-8") as f:
                f.write(soup.prettify())
            print(" Page saved to yantra_nepal_page.html\n")

            # Find all product containers - Yantra Nepal Elementor structure
            products = soup.find_all("div", class_=lambda x: x and "e-loop-item" in x and "product" in x)
            if not products:
                # Try finding WooCommerce products
                products = soup.find_all("div", class_="product")
            if not products:
                # Try alternative selectors
                products = soup.find_all("article", class_="product")

            print(f" Found {len(products)} product containers\n")

            results = []

            for idx, product in enumerate(products[:max_results], 1):
                try:
                    print(f"Processing item {idx}...")

                    # TITLE - Yantra Nepal Elementor structure
                    title = None
                    title_elem = product.find("h2", class_="product_title")
                    if title_elem:
                        a_tag = title_elem.find("a")
                        if a_tag:
                            title = a_tag.get_text(strip=True)

                    if not title:
                        title_elem = product.find("a", href=lambda x: x and "yantranepal.com" in x)
                        if title_elem:
                            title = title_elem.get_text(strip=True)

                    if not title or len(title) < 5:
                        print(f" Skipped - No valid title\n")
                        continue

                    print(f"  Title: {title[:60].encode('ascii', 'ignore').decode('ascii')}...")

                    # PRICE - Yantra Nepal Elementor structure
                    price = None

                    # Try Yantra Nepal's specific price structure (including hidden elements)
                    price_elem = product.find("p", class_="price")
                    if price_elem:
                        amount_elem = price_elem.find("span", class_="woocommerce-Price-amount")
                        if amount_elem:
                            bdi_elem = amount_elem.find("bdi")
                            if bdi_elem:
                                price_text = bdi_elem.get_text(strip=True)
                                price = self.clean_price(price_text)

                    if not price:
                        # Try finding any woocommerce-Price-amount within the product
                        amount_elem = product.find("span", class_="woocommerce-Price-amount")
                        if amount_elem:
                            bdi_elem = amount_elem.find("bdi")
                            if bdi_elem:
                                price_text = bdi_elem.get_text(strip=True)
                                price = self.clean_price(price_text)

                    if not price:
                        # Try finding price in any element with Rs. followed by digits
                        price_text_match = product.find(string=re.compile(r'Rs\.\s*[\d,]+\.?\d*'))
                        if price_text_match:
                            price = self.clean_price(price_text_match.strip())

                    if not price:
                        # Last resort: search for any numeric price pattern
                        all_text = product.get_text()
                        price_match = re.search(r'Rs\.?\s*([\d,]+\.?\d*)', all_text)
                        if price_match:
                            price = self.clean_price(price_match.group(1))

                    if not price or price == 0:
                        print(f" Skipped - No valid price\n")
                        continue

                    print(f"  Price: Rs.{price:,.2f}")

                    # LINK - Yantra Nepal Elementor structure
                    link = None
                    title_elem = product.find("h2", class_="product_title")
                    if title_elem:
                        link_elem = title_elem.find("a")
                        if link_elem and "href" in link_elem.attrs:
                            link = link_elem["href"]

                    if not link:
                        # Try finding any link within the product
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

                    # Extract brand
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
            products = self.scrape_yantra_nepal("laptop", max_results=20)

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
            else:
                print("\n  No products were scraped successfully")
                print(" Check yantra_nepal_page.html to see what was loaded")

        except Exception as e:
            print(f"\n Fatal error: {e}")
        finally:
            super().close()

class HukutScraper(BaseScraper):
    def __init__(self):
        super().__init__("Hukut")

    def scrape_hukut(self, keyword="laptop", max_results=20):
        print(f"\n{'='*60}")
        print(f" Scraping Hukut for: {keyword}")
        print(f"{'='*60}\n")

        url = f"https://hukut.com/search?q={keyword}"

        try:
            self.driver.get(url)
            print(" Loading page...")
            time.sleep(3)  # Initial wait

            # Wait for products to load (SPA content)
            print(" Waiting for products to load...")
            try:
                WebDriverWait(self.driver, 15).until(
                    lambda driver: len(driver.find_elements(By.CSS_SELECTOR, "[data-testid], .card, .product, .item")) > 0
                )
                print(" Products loaded!")
            except:
                print(" Timeout waiting for products, continuing anyway...")

            # Scroll to load more items and wait for dynamic content
            print(" Scrolling to load more content...")
            for i in range(3):
                self.driver.execute_script(f"window.scrollTo(0, {(i+1)*1000});")
                time.sleep(2)

            # Save for debugging
            with open("hukut_page.html", "w", encoding="utf-8") as f:
                f.write(self.driver.page_source)
            print(" Page saved to hukut_page.html\n")

            # For SPA sites, try to get products directly from browser DOM
            print(" Extracting products from browser DOM...")
            products = []
            try:
                # Try to find products using JavaScript
                product_elements = self.driver.execute_script("""
                    // Try various selectors for products
                    let selectors = [
                        '[data-testid]',
                        '.card',
                        '.product',
                        '.item',
                        'article',
                        '[class*="product"]',
                        '[class*="card"]'
                    ];

                    for (let selector of selectors) {
                        let elements = document.querySelectorAll(selector);
                        if (elements.length > 0) {
                            return Array.from(elements).map(el => ({
                                html: el.outerHTML,
                                text: el.textContent,
                                href: el.href || (el.querySelector('a') ? el.querySelector('a').href : null)
                            }));
                        }
                    }
                    return [];
                """)

                if product_elements and len(product_elements) > 0:
                    products = product_elements
                    print(f" Found {len(products)} products via JavaScript")
                    # Debug: print first product
                    if len(product_elements) > 0:
                        print(f" Sample product text: {product_elements[0]['text'][:100]}...")
                else:
                    # Fallback to BeautifulSoup
                    soup = BeautifulSoup(self.driver.page_source, 'html.parser')
                    products = soup.find_all("div", {"data-testid": True})
                    if not products:
                        products = soup.find_all("article")
                    print(f" Found {len(products)} products via BeautifulSoup")

            except Exception as e:
                print(f" Error extracting products: {e}")
                soup = BeautifulSoup(self.driver.page_source, 'html.parser')
                products = soup.find_all("article")

            print(f" Found {len(products)} product containers\n")

            results = []

            for idx, product in enumerate(products[:max_results], 1):
                try:
                    print(f"Processing item {idx}...")

                    # Handle different data structures (JavaScript vs BeautifulSoup)
                    if isinstance(product, dict) and 'text' in product:
                        # JavaScript extracted data
                        all_text = product['text']
                        link = product.get('href')
                        html_content = product['html']
                    else:
                        # BeautifulSoup element
                        all_text = product.get_text()
                        link = None
                        if product.name == "a" and "href" in product.attrs:
                            link = product["href"]
                        else:
                            link_elem = product.find("a")
                            if link_elem and "href" in link_elem.attrs:
                                link = link_elem["href"]

                        if link and not link.startswith("http"):
                            link = f"https://hukut.com{link}"

                    # TITLE - Extract from text content
                    title = None
                    # Try to find a reasonable title (usually the longest text segment)
                    text_parts = [part.strip() for part in all_text.split('\n') if part.strip() and len(part.strip()) > 10]
                    if text_parts:
                        title = text_parts[0]  # Take the first substantial text

                    if not title or len(title) < 5:
                        print(f" Skipped - No valid title\n")
                        continue

                    print(f"  Title: {title[:60].encode('ascii', 'ignore').decode('ascii')}...")

                    # PRICE - Hukut SPA structure
                    price = None
                    price_match = re.search(r'Rs\.?\s*([\d,]+\.?\d*)', all_text)
                    if price_match:
                        price = self.clean_price(price_match.group(1))

                    if not price or price == 0:
                        print(f" Skipped - No valid price\n")
                        continue

                    print(f"  Price: Rs.{price:,.2f}")

                    if not link:
                        print(f"  Skipped - No link\n")
                        continue

                    # IMAGE
                    image_url = None
                    img_elem = product.find("img", class_="product-image")
                    if img_elem and "src" in img_elem.attrs:
                        image_url = img_elem["src"]

                    if not image_url:
                        img_elem = product.find("img")
                        if img_elem and "src" in img_elem.attrs:
                            image_url = img_elem["src"]

                    # Extract brand
                    brand = self.extract_brand(title)

                    results.append({
                        "name": title[:255],
                        "brand": brand,
                        "price": price,
                        "url": link,
                        "image": image_url,
                        "retailer": "Hukut"
                    })

                    print(f"Added to results\n")

                except Exception as e:
                    print(f" Error: {e}\n")
                    continue

            return results

        except Exception as e:
            print(f" Failed to scrape Hukut: {e}")
            return []

    def run(self):
        if not self.driver:
            print(f"\nSkipping {self.retailer} scraper - driver not available")
            return

        try:
            products = self.scrape_hukut("laptop", max_results=20)

            print(f"\n{'='*60}")
            print(f" HUKUT SCRAPING RESULTS")
            print(f"{'='*60}")
            print(f"Total products found: {len(products)}")

            if products:
                print(f"\nSample products:")
                for i, p in enumerate(products[:3], 1):
                    print(f"\n{i}. {p['name'][:60]}...")
                    print(f"   Brand: {p['brand']}")
                    print(f"   Price: Rs.{p['price']:,.2f}")

                super().save_to_db(products)
            else:
                print("\n  No products were scraped successfully")
                print(" Check hukut_page.html to see what was loaded")

        except Exception as e:
            print(f"\n Fatal error: {e}")
        finally:
            super().close()

class NeoStoreScraper(BaseScraper):
    def __init__(self):
        super().__init__("Neo Store")

    def scrape_neostore(self, keyword="laptop", max_results=20):
        print(f"\n{'='*60}")
        print(f" Scraping Neo Store for: {keyword}")
        print(f"{'='*60}\n")

        url = "https://www.neostore.com.np/product-category/laptops-computers/"

        try:
            self.driver.get(url)
            print(" Loading page...")
            time.sleep(3)

            # Scroll to load more items
            print(" Scrolling to load items...")
            for i in range(3):
                self.driver.execute_script(f"window.scrollTo(0, {(i+1)*1000});")
                time.sleep(1)

            soup = BeautifulSoup(self.driver.page_source, 'html.parser')

            # Save for debugging
            with open("neostore_page.html", "w", encoding="utf-8") as f:
                f.write(soup.prettify())
            print(" Page saved to neostore_page.html\n")

            # Find all product containers - Neo Store WooCommerce structure
            products = soup.find_all("div", class_="product")
            if not products:
                products = soup.find_all("li", class_="product")
            if not products:
                # Try finding WooCommerce products
                products = soup.find_all("div", class_="woocommerce-LoopProduct-link")

            print(f" Found {len(products)} product containers\n")

            results = []

            for idx, product in enumerate(products[:max_results], 1):
                try:
                    print(f"Processing item {idx}...")

                    # TITLE
                    title = None
                    title_elem = product.find("h2", class_="woocommerce-loop-product__title")
                    if title_elem:
                        title = title_elem.get_text(strip=True)

                    if not title:
                        title_elem = product.find("h3")
                        if title_elem:
                            title = title_elem.get_text(strip=True)

                    if not title:
                        title_elem = product.find("a", class_="woocommerce-LoopProduct-link")
                        if title_elem:
                            title = title_elem.get_text(strip=True)

                    if not title or len(title) < 5:
                        print(f" Skipped - No valid title\n")
                        continue

                    print(f"  Title: {title[:60].encode('ascii', 'ignore').decode('ascii')}...")

                    # PRICE
                    price = None

                    # Try different price selectors for Neo Store
                    price_elem = product.find("span", class_="woocommerce-Price-amount")
                    if price_elem:
                        bdi_elem = price_elem.find("bdi")
                        if bdi_elem:
                            price_text = bdi_elem.get_text(strip=True)
                            price = self.clean_price(price_text)

                    if not price:
                        price_elem = product.find("bdi")
                        if price_elem:
                            price = self.clean_price(price_elem.get_text(strip=True))

                    if not price:
                        price_elem = product.find("span", class_="price")
                        if price_elem:
                            price = self.clean_price(price_elem.get_text(strip=True))

                    if not price:
                        # Try finding price in any element with Rs. or similar
                        price_elem = product.find(string=re.compile(r'Rs\.|₹'))
                        if price_elem:
                            price = self.clean_price(price_elem.strip())

                    if not price or price == 0:
                        print(f" Skipped - No valid price\n")
                        continue

                    print(f"  Price: Rs.{price:,.2f}")

                    # LINK
                    link = None
                    link_elem = product.find("a", class_="woocommerce-LoopProduct-link")
                    if link_elem and "href" in link_elem.attrs:
                        link = link_elem["href"]

                    if not link:
                        link_elem = product.find("a")
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

                    # Extract brand
                    brand = self.extract_brand(title)

                    results.append({
                        "name": title[:255],
                        "brand": brand,
                        "price": price,
                        "url": link,
                        "image": image_url,
                        "retailer": "Neo Store"
                    })

                    print(f"Added to results\n")

                except Exception as e:
                    print(f" Error: {e}\n")
                    continue

            return results

        except Exception as e:
            print(f" Failed to scrape Neo Store: {e}")
            return []

    def run(self):
        if not self.driver:
            print(f"\nSkipping {self.retailer} scraper - driver not available")
            return

        try:
            products = self.scrape_neostore("laptop", max_results=20)

            print(f"\n{'='*60}")
            print(f" NEO STORE SCRAPING RESULTS")
            print(f"{'='*60}")
            print(f"Total products found: {len(products)}")

            if products:
                print(f"\nSample products:")
                for i, p in enumerate(products[:3], 1):
                    print(f"\n{i}. {p['name'][:60]}...")
                    print(f"   Brand: {p['brand']}")
                    print(f"   Price: Rs.{p['price']:,.2f}")

                super().save_to_db(products)
            else:
                print("\n  No products were scraped successfully")
                print(" Check neostore_page.html to see what was loaded")

        except Exception as e:
            print(f"\n Fatal error: {e}")
        finally:
            super().close()

class OninScraper(BaseScraper):
    def __init__(self):
        super().__init__("Onin")

    def scrape_onin(self, keyword="laptop", max_results=20):
        print(f"\n{'='*60}")
        print(f" Scraping Onin for: {keyword}")
        print(f"{'='*60}\n")

        url = "https://onin.com.np/products?category=&search=laptop"

        try:
            self.driver.get(url)
            print(" Loading page...")
            time.sleep(3)

            # Scroll to load more items
            print(" Scrolling to load items...")
            for i in range(3):
                self.driver.execute_script(f"window.scrollTo(0, {(i+1)*1000});")
                time.sleep(1)

            soup = BeautifulSoup(self.driver.page_source, 'html.parser')

            # Save for debugging
            with open("onin_page.html", "w", encoding="utf-8") as f:
                f.write(soup.prettify())
            print(" Page saved to onin_page.html\n")

            # Find all product containers - Onin structure
            products = soup.find_all("div", class_="product-card")
            if not products:
                products = soup.find_all("div", class_="product-item")
            if not products:
                # Try alternative selectors
                products = soup.find_all("article", class_="product")

            print(f" Found {len(products)} product containers\n")

            results = []

            for idx, product in enumerate(products[:max_results], 1):
                try:
                    print(f"Processing item {idx}...")

                    # TITLE - Onin structure
                    title = None
                    title_elem = product.find("h3", class_="product-title")  # Onin uses h3.product-title
                    if title_elem:
                        title = title_elem.get_text(strip=True)

                    if not title:
                        # Try finding title in h4 elements
                        title_elem = product.find("h4")
                        if title_elem:
                            title = title_elem.get_text(strip=True)

                    if not title:
                        # Try finding title in links
                        link_elem = product.find("a")
                        if link_elem:
                            title = link_elem.get_text(strip=True)

                    if not title or len(title) < 5:
                        print(f" Skipped - No valid title\n")
                        continue

                    print(f"  Title: {title[:60].encode('ascii', 'ignore').decode('ascii')}...")

                    # PRICE - Onin structure
                    price = None

                    # Try Onin's price structure - h4.product-price (prefer non-strikethrough price)
                    price_elem = product.find("h4", class_="product-price")
                    if price_elem:
                        # Check if there's a discounted price (not in <del> tag)
                        del_tag = price_elem.find("del")
                        if del_tag:
                            # Remove the del tag content and get the remaining text
                            for del_element in price_elem.find_all("del"):
                                del_element.decompose()
                            price_text = price_elem.get_text(strip=True)
                        else:
                            price_text = price_elem.get_text(strip=True)
                        price = self.clean_price(price_text)

                    if not price:
                        # Try finding price in span with currency (Rs. or रू.)
                        price_elem = product.find("span", string=re.compile(r'Rs\.|रू\.'))
                        if price_elem:
                            price = self.clean_price(price_elem.get_text(strip=True))

                    if not price:
                        # Try finding price anywhere in the product (Rs. or रू.)
                        all_text = product.get_text()
                        price_match = re.search(r'(?:Rs\.|रू\.)\s*([\d,]+\.?\d*)', all_text)
                        if price_match:
                            price = self.clean_price(price_match.group(0))

                    if not price or price == 0:
                        print(f" Skipped - No valid price\n")
                        continue

                    print(f"  Price: Rs.{price:,.2f}")

                    # LINK - Onin structure
                    link = None
                    link_elem = product.find("a")
                    if link_elem and "href" in link_elem.attrs:
                        href = link_elem["href"]
                        link = f"https://onin.com.np{href}" if href.startswith("/") else href

                    if not link:
                        print(f"  Skipped - No link\n")
                        continue

                    # IMAGE
                    image_url = None
                    img_elem = product.find("img", class_="product-image")
                    if img_elem and "src" in img_elem.attrs:
                        image_url = img_elem["src"]

                    if not image_url:
                        img_elem = product.find("img")
                        if img_elem and "src" in img_elem.attrs:
                            image_url = img_elem["src"]

                    # Extract brand
                    brand = self.extract_brand(title)

                    results.append({
                        "name": title[:255],
                        "brand": brand,
                        "price": price,
                        "url": link,
                        "image": image_url,
                        "retailer": "Onin"
                    })

                    print(f"Added to results\n")

                except Exception as e:
                    print(f" Error: {e}\n")
                    continue

            return results

        except Exception as e:
            print(f" Failed to scrape Onin: {e}")
            return []

    def run(self):
        if not self.driver:
            print(f"\nSkipping {self.retailer} scraper - driver not available")
            return

        try:
            products = self.scrape_onin("laptop", max_results=20)

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
            else:
                print("\n  No products were scraped successfully")
                print(" Check onin_page.html to see what was loaded")

        except Exception as e:
            print(f"\n Fatal error: {e}")
        finally:
            super().close()

class ComputerDurbarScraper(BaseScraper):
    def __init__(self):
        super().__init__("Computer Durbar")

    def scrape_computer_durbar(self, keyword="laptops", max_results=20, max_pages=3):
        print(f"\n{'='*60}")
        print(f" Scraping Computer Durbar for: {keyword}")
        print(f"{'='*60}\n")

        all_products = []

        for page in range(1, max_pages + 1):
            print(f" Scraping page {page}...")
            url = f"https://computerdurbar.com/page/{page}/?product_cat&s={keyword}&post_type=product"

            try:
                self.driver.get(url)
                print(f" Loading page {page}...")
                time.sleep(3)

                # Scroll to load more items
                print(" Scrolling to load items...")
                for i in range(3):
                    self.driver.execute_script(f"window.scrollTo(0, {(i+1)*1000});")
                    time.sleep(1)

                soup = BeautifulSoup(self.driver.page_source, 'html.parser')

                # Save first page for debugging
                if page == 1:
                    with open("computer_durbar_page.html", "w", encoding="utf-8") as f:
                        f.write(soup.prettify())
                    print(" Page saved to computer_durbar_page.html\n")

                # Find all product containers - Computer Durbar uses WooCommerce structure
                products = soup.find_all("div", class_="product")
                if not products:
                    # Try alternative selectors
                    products = soup.find_all("li", class_="product")
                if not products:
                    # Try another alternative
                    products = soup.find_all("article", class_="product")

                print(f" Found {len(products)} product containers on page {page}")

                # If no products found on this page, we've likely reached the end
                if not products:
                    print(f" No more products found on page {page}, stopping pagination")
                    break

                # Process products from this page
                for idx, product in enumerate(products, 1):
                    if len(all_products) >= max_results:
                        break

                    try:
                        print(f"Processing item {len(all_products) + idx}...")

                        # TITLE - Computer Durbar WooCommerce structure
                        title = None
                        title_elem = product.find("h2", class_="woocommerce-loop-product__title")
                        if title_elem:
                            title = title_elem.get_text(strip=True)

                        if not title:
                            title_elem = product.find("a", class_="woocommerce-LoopProduct-link")
                            if title_elem:
                                title = title_elem.get_text(strip=True)

                        if not title or len(title) < 5:
                            print(f" Skipped - No valid title\n")
                            continue

                        print(f"  Title: {title[:60].encode('ascii', 'ignore').decode('ascii')}...")

                        # PRICE - Computer Durbar WooCommerce structure
                        price = None

                        # Try WooCommerce price structure
                        price_elem = product.find("span", class_="woocommerce-Price-amount")
                        if price_elem:
                            bdi_elem = price_elem.find("bdi")
                            if bdi_elem:
                                price_text = bdi_elem.get_text(strip=True)
                                price = self.clean_price(price_text)

                        if not price:
                            # Try alternative price selector
                            price_elem = product.find("bdi")
                            if price_elem:
                                price = self.clean_price(price_elem.get_text(strip=True))

                        if not price:
                            # Try finding price anywhere in the product
                            all_text = product.get_text()
                            price_match = re.search(r'Rs\.?\s*([\d,]+\.?\d*)', all_text)
                            if price_match:
                                price = self.clean_price(price_match.group(0))

                        if not price or price == 0:
                            print(f" Skipped - No valid price\n")
                            continue

                        print(f"  Price: Rs.{price:,.2f}")

                        # LINK - Computer Durbar WooCommerce structure
                        link = None
                        link_elem = product.find("a", class_="woocommerce-LoopProduct-link")
                        if link_elem and "href" in link_elem.attrs:
                            link = link_elem["href"]

                        if not link:
                            link_elem = product.find("a")
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

                        # Extract brand
                        brand = self.extract_brand(title)

                        all_products.append({
                            "name": title[:255],
                            "brand": brand,
                            "price": price,
                            "url": link,
                            "image": image_url,
                            "retailer": "Computer Durbar"
                        })

                        print(f"Added to results\n")

                    except Exception as e:
                        print(f" Error: {e}\n")
                        continue

                # Check if we've reached the max results
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
            products = self.scrape_computer_durbar("laptops", max_results=20, max_pages=3)

            print(f"\n{'='*60}")
            print(f" COMPUTER DURBAR SCRAPING RESULTS")
            print(f"{'='*60}")
            print(f"Total products found: {len(products)}")

            if products:
                print(f"\nSample products:")
                for i, p in enumerate(products[:3], 1):
                    print(f"\n{i}. {p['name'][:60]}...")
                    print(f"   Brand: {p['brand']}")
                    print(f"   Price: Rs.{p['price']:,.2f}")

                super().save_to_db(products)
            else:
                print("\n  No products were scraped successfully")
                print(" Check computer_durbar_page.html to see what was loaded")

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
            time.sleep(3)

            # Scroll to load more items
            print(" Scrolling to load items...")
            for i in range(3):
                self.driver.execute_script(f"window.scrollTo(0, {(i+1)*1000});")
                time.sleep(1)

            soup = BeautifulSoup(self.driver.page_source, 'html.parser')

            # Save for debugging
            with open("daraz_page.html", "w", encoding="utf-8") as f:
                f.write(soup.prettify())
            print(" Page saved to daraz_page.html\n")

            # Find all product containers - Daraz uses different class names
            products = soup.find_all("div", class_="Bm3ON")
            if not products:
                # Try alternative selectors
                products = soup.find_all("div", {"data-item-id": True})
            if not products:
                # Try another alternative - look for product cards
                products = soup.find_all("div", class_="item-card")

            print(f" Found {len(products)} product containers\n")

            results = []

            for idx, product in enumerate(products[:max_results], 1):
                try:
                    print(f"Processing item {idx}...")

                    # TITLE
                    title = None

                    # Method 1: Look for any link with title attribute (most reliable)
                    all_links = product.find_all("a")
                    for link in all_links:
                        if "title" in link.attrs and link.get_text(strip=True):
                            title = link.get_text(strip=True)
                            break

                    # Method 2: Look for div with RfADt class
                    if not title:
                        title_elem = product.find("div", class_="RfADt")
                        if title_elem:
                            a_tag = title_elem.find("a")
                            if a_tag:
                                title = a_tag.get_text(strip=True)

                    # Method 3: Look for any div containing a link
                    if not title:
                        divs_with_links = product.find_all("div")
                        for div in divs_with_links:
                            a_tag = div.find("a")
                            if a_tag and a_tag.get_text(strip=True):
                                title = a_tag.get_text(strip=True)
                                break

                    # Method 4: Try old selectors as fallback
                    if not title:
                        title_elem = product.find("a", class_="link--WBvkq")
                        if title_elem:
                            title = title_elem.get_text(strip=True)

                    if not title or len(title) < 5:
                        print(f" Skipped - No valid title\n")
                        continue

                    print(f"  Title: {title[:60].encode('ascii', 'ignore').decode('ascii')}...")

                    # PRICE
                    price = None

                    # Try different price selectors for Daraz
                    price_elem = product.find("div", class_="aBrP0")
                    if price_elem:
                        price_span = price_elem.find("span", class_="ooOxS")
                        if price_span:
                            price_text = price_span.get_text(strip=True)
                            price = self.clean_price(price_text)

                    if not price:
                        # Try alternative price selector
                        price_elem = product.find("span", class_="currency--GVKjl")
                        if price_elem:
                            price_parent = price_elem.parent
                            if price_parent:
                                price = self.clean_price(price_parent.get_text(strip=True))

                    if not price:
                        # Try another price pattern
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
                        # Try alternative link selector
                        link_elem = product.find("a", class_="link--WBvkq")
                        if link_elem and "href" in link_elem.attrs:
                            href = link_elem["href"]
                            link = f"https:{href}" if href.startswith("//") else href

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
                        # Try alternative image selector
                        img_elem = product.find("img", class_="image--SxaF8")
                        if img_elem and "src" in img_elem.attrs:
                            image_url = img_elem["src"]

                    # Extract brand
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
            products = self.scrape_daraz("laptop", max_results=20)

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
            else:
                print("\n  No products were scraped successfully")
                print(" Check daraz_page.html to see what was loaded")

        except Exception as e:
            print(f"\n Fatal error: {e}")
        finally:
            super().close()

if __name__ == "__main__":
    print("""
        Multi-Website Laptop Scraper
        Scraping from: Mudita, Daraz, Nagmani, Maxell, Yantra Nepal, Hukut, Neo Store, Onin, Computer Durbar
    """)

    # Run Mudita scraper
    print("\n" + "="*80)
    print("STARTING MUDITA SCRAPER")
    print("="*80)
    mudita_scraper = MuditaScraper()
    mudita_scraper.run()

    # Run Daraz scraper
    print("\n" + "="*80)
    print("STARTING DARAZ SCRAPER")
    print("="*80)
    daraz_scraper = DarazScraper()
    daraz_scraper.run()

    # Run Nagmani scraper
    print("\n" + "="*80)
    print("STARTING NAGMANI SCRAPER")
    print("="*80)
    nagmani_scraper = NagmaniScraper()
    nagmani_scraper.run()

    # Run Maxell scraper
    print("\n" + "="*80)
    print("STARTING MAXELL SCRAPER")
    print("="*80)
    maxell_scraper = MaxellScraper()
    maxell_scraper.run()

    # Run Yantra Nepal scraper
    print("\n" + "="*80)
    print("STARTING YANTRA NEPAL SCRAPER")
    print("="*80)
    yantra_nepal_scraper = YantraNepalScraper()
    yantra_nepal_scraper.run()

    # Run Hukut scraper
    print("\n" + "="*80)
    print("STARTING HUKUT SCRAPER")
    print("="*80)
    hukut_scraper = HukutScraper()
    hukut_scraper.run()

    # Run Neo Store scraper
    print("\n" + "="*80)
    print("STARTING NEO STORE SCRAPER")
    print("="*80)
    neostore_scraper = NeoStoreScraper()
    neostore_scraper.run()

    # Run Onin scraper
    print("\n" + "="*80)
    print("STARTING ONIN SCRAPER")
    print("="*80)
    onin_scraper = OninScraper()
    onin_scraper.run()

    # Run Computer Durbar scraper
    print("\n" + "="*80)
    print("STARTING COMPUTER DURBAR SCRAPER")
    print("="*80)
    computer_durbar_scraper = ComputerDurbarScraper()
    computer_durbar_scraper.run()

    print("\n" + "="*80)
    print("ALL SCRAPERS COMPLETED")
    print("="*80)