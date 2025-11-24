#!/usr/bin/env python3
"""
Laptop Price Scraper for Amazon and Flipkart
Save this file as: scrape.py

Usage:
    python3 scrape.py

Requirements:
    pip install beautifulsoup4 requests mysql-connector-python
"""
import requests
from bs4 import BeautifulSoup
from mysql import connector
from datetime import datetime
import time
import re

# ============================================
# DATABASE CONFIGURATION
# ============================================
DB_CONFIG = {
    'host': 'localhost',
    'user': 'root',          # Change this
    'password': '',          # Change this
    'database': 'laptop_comparison'
}

# ============================================
# SCRAPER CONFIGURATION
# ============================================
HEADERS = {
    'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
    'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
    'Accept-Language': 'en-US,en;q=0.9',
    'Accept-Encoding': 'gzip, deflate, br',
    'Connection': 'keep-alive',
    'Upgrade-Insecure-Requests': '1'
}

# Rate limiting
REQUEST_DELAY = 3  # seconds between requests

class LaptopScraper:
    """Main scraper class for collecting laptop prices"""
    
    def __init__(self):
        """Initialize database connection"""
        try:
            self.conn = mysql.connector.connect(**DB_CONFIG)
            self.cursor = self.conn.cursor(dictionary=True)
            print("✓ Database connected successfully")
        except mysql.connector.Error as err:
            print(f"✗ Database connection failed: {err}")
            sys.exit(1)
    
    def clean_price(self, price_str):
        """
        Extract numeric price from string
        Examples: "₹45,999" -> 45999.0, "$999.99" -> 999.99
        """
        if not price_str:
            return None
        
        # Remove currency symbols and commas
        price = re.sub(r'[^\d.]', '', str(price_str))
        
        try:
            return float(price)
        except (ValueError, TypeError):
            return None
    
    def extract_brand(self, name):
        """Extract brand name from product title"""
        brands = [
            'Dell', 'HP', 'Lenovo', 'ASUS', 'Acer', 'Apple', 
            'MSI', 'Samsung', 'Microsoft', 'Razer', 'Alienware',
            'LG', 'Huawei', 'Xiaomi', 'Avita', 'iBall'
        ]
        
        name_lower = name.lower()
        for brand in brands:
            if brand.lower() in name_lower:
                return brand
        
        return 'Other'
    
    def scrape_amazon_search(self, keyword='laptop', max_products=10):
        """
        Scrape Amazon India for laptop listings
        
        NOTE: Amazon actively blocks scrapers. This is for educational purposes.
        Use Amazon Product Advertising API for production.
        """
        print(f"\n🔍 Scraping Amazon for '{keyword}'...")
        
        url = f"https://www.amazon.in/s?k={keyword}"
        
        try:
            response = requests.get(url, headers=HEADERS, timeout=10)
            response.raise_for_status()
            
            soup = BeautifulSoup(response.content, 'html.parser')
            
            # Find product containers (selectors may change)
            products = soup.find_all('div', {'data-component-type': 's-search-result'})
            
            results = []
            
            for idx, product in enumerate(products[:max_products]):
                try:
                    # Extract title
                    title_elem = product.find('h2', class_='a-size-mini')
                    if not title_elem:
                        title_elem = product.find('span', class_='a-size-medium')
                    
                    if not title_elem:
                        continue
                    
                    title = title_elem.get_text(strip=True)
                    
                    # Extract price
                    price_elem = product.find('span', class_='a-price-whole')
                    if not price_elem:
                        continue
                    
                    price = self.clean_price(price_elem.get_text())
                    if not price or price < 10000:  # Filter out accessories
                        continue
                    
                    # Extract link
                    link_elem = product.find('a', class_='a-link-normal')
                    product_url = 'https://www.amazon.in' + link_elem['href'] if link_elem else None
                    
                    # Extract image
                    img_elem = product.find('img', class_='s-image')
                    image_url = img_elem['src'] if img_elem and 'src' in img_elem.attrs else None
                    
                    # Extract specs from title
                    specs = self.extract_specs_from_title(title)
                    
                    results.append({
                        'name': title[:255],  # Limit length
                        'brand': self.extract_brand(title),
                        'price': price,
                        'url': product_url,
                        'image': image_url,
                        'retailer': 'Amazon',
                        'processor': specs.get('processor'),
                        'ram': specs.get('ram'),
                        'storage': specs.get('storage')
                    })
                    
                    print(f"  ✓ Found: {title[:50]}... - ₹{price}")
                    
                except Exception as e:
                    print(f"  ✗ Error parsing product {idx}: {e}")
                    continue
            
            print(f"✓ Amazon: Found {len(results)} products")
            return results
            
        except requests.RequestException as e:
            print(f"✗ Amazon request failed: {e}")
            return []
        except Exception as e:
            print(f"✗ Amazon scraping error: {e}")
            return []
    
    def scrape_flipkart_search(self, keyword='laptop', max_products=10):
        """
        Scrape Flipkart for laptop listings
        
        NOTE: Flipkart also blocks scrapers. Use Flipkart Affiliate API for production.
        """
        print(f"\n🔍 Scraping Flipkart for '{keyword}'...")
        
        url = f"https://www.flipkart.com/search?q={keyword}"
        
        try:
            response = requests.get(url, headers=HEADERS, timeout=10)
            response.raise_for_status()
            
            soup = BeautifulSoup(response.content, 'html.parser')
            
            # Find product containers (selectors may change frequently)
            products = soup.find_all('div', class_='_1AtVbE')
            
            if not products:
                # Try alternative selector
                products = soup.find_all('div', class_='_2kHMtA')
            
            results = []
            
            for idx, product in enumerate(products[:max_products]):
                try:
                    # Extract title
                    title_elem = product.find('div', class_='_4rR01T')
                    if not title_elem:
                        title_elem = product.find('a', class_='s1Q9rs')
                    
                    if not title_elem:
                        continue
                    
                    title = title_elem.get_text(strip=True)
                    
                    # Extract price
                    price_elem = product.find('div', class_='_30jeq3')
                    if not price_elem:
                        price_elem = product.find('div', class_='_25b18c')
                    
                    if not price_elem:
                        continue
                    
                    price = self.clean_price(price_elem.get_text())
                    if not price or price < 10000:
                        continue
                    
                    # Extract link
                    link_elem = product.find('a', class_='_1fQZEK')
                    if not link_elem:
                        link_elem = product.find('a', class_='s1Q9rs')
                    
                    product_url = 'https://www.flipkart.com' + link_elem['href'] if link_elem else None
                    
                    # Extract image
                    img_elem = product.find('img', class_='_396cs4')
                    if not img_elem:
                        img_elem = product.find('img')
                    
                    image_url = img_elem['src'] if img_elem and 'src' in img_elem.attrs else None
                    
                    # Extract specs
                    specs = self.extract_specs_from_title(title)
                    
                    results.append({
                        'name': title[:255],
                        'brand': self.extract_brand(title),
                        'price': price,
                        'url': product_url,
                        'image': image_url,
                        'retailer': 'Flipkart',
                        'processor': specs.get('processor'),
                        'ram': specs.get('ram'),
                        'storage': specs.get('storage')
                    })
                    
                    print(f"  ✓ Found: {title[:50]}... - ₹{price}")
                    
                except Exception as e:
                    print(f"  ✗ Error parsing product {idx}: {e}")
                    continue
            
            print(f"✓ Flipkart: Found {len(results)} products")
            return results
            
        except requests.RequestException as e:
            print(f"✗ Flipkart request failed: {e}")
            return []
        except Exception as e:
            print(f"✗ Flipkart scraping error: {e}")
            return []
    
    def extract_specs_from_title(self, title):
        """Extract RAM, processor, storage from product title"""
        specs = {
            'processor': None,
            'ram': None,
            'storage': None
        }
        
        title_lower = title.lower()
        
        # Extract RAM
        ram_match = re.search(r'(\d+)\s*gb\s*(ram|ddr)', title_lower)
        if ram_match:
            specs['ram'] = f"{ram_match.group(1)}GB"
        
        # Extract Storage
        storage_match = re.search(r'(\d+)\s*(gb|tb)\s*(ssd|hdd)', title_lower)
        if storage_match:
            specs['storage'] = f"{storage_match.group(1)}{storage_match.group(2).upper()} {storage_match.group(3).upper()}"
        
        # Extract Processor (simplified)
        if 'intel' in title_lower:
            if 'i7' in title_lower:
                specs['processor'] = 'Intel Core i7'
            elif 'i5' in title_lower:
                specs['processor'] = 'Intel Core i5'
            elif 'i3' in title_lower:
                specs['processor'] = 'Intel Core i3'
        elif 'ryzen' in title_lower:
            if 'ryzen 7' in title_lower:
                specs['processor'] = 'AMD Ryzen 7'
            elif 'ryzen 5' in title_lower:
                specs['processor'] = 'AMD Ryzen 5'
            elif 'ryzen 3' in title_lower:
                specs['processor'] = 'AMD Ryzen 3'
        elif 'm1' in title_lower or 'm2' in title_lower:
            specs['processor'] = 'Apple Silicon'
        
        return specs
    
    def save_to_database(self, products):
        """Save or update products in database"""
        print(f"\n💾 Saving {len(products)} products to database...")
        
        saved_count = 0
        updated_count = 0
        
        for product in products:
            try:
                # Check if laptop exists
                self.cursor.execute(
                    "SELECT id FROM laptops WHERE name = %s",
                    (product['name'],)
                )
                existing = self.cursor.fetchone()
                
                if not existing:
                    # Insert new laptop
                    self.cursor.execute("""
                        INSERT INTO laptops (name, brand, image_url, processor, ram, storage, specs)
                        VALUES (%s, %s, %s, %s, %s, %s, %s)
                    """, (
                        product['name'],
                        product['brand'],
                        product['image'],
                        product.get('processor', ''),
                        product.get('ram', ''),
                        product.get('storage', ''),
                        'Auto-scraped product'
                    ))
                    laptop_id = self.cursor.lastrowid
                    saved_count += 1
                    print(f"  ✓ Added laptop: {product['name'][:50]}")
                else:
                    laptop_id = existing['id']
                    updated_count += 1
                
                # Update or insert price
                self.cursor.execute("""
                    INSERT INTO prices (laptop_id, retailer, price, product_url, last_updated)
                    VALUES (%s, %s, %s, %s, NOW())
                    ON DUPLICATE KEY UPDATE
                        price = VALUES(price),
                        product_url = VALUES(product_url),
                        last_updated = NOW()
                """, (laptop_id, product['retailer'], product['price'], product['url']))
                
                self.conn.commit()
                
            except mysql.connector.Error as e:
                print(f"  ✗ Database error for {product['name'][:50]}: {e}")
                self.conn.rollback()
                continue
        
        print(f"\n✓ Summary: {saved_count} new laptops, {updated_count} updated")
    
    def run(self, keywords=['laptop', 'gaming laptop']):
        """Main execution workflow"""
        print("=" * 60)
        print("  LAPTOP PRICE SCRAPER")
        print("=" * 60)
        print(f"Started at: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n")
        
        all_products = []
        
        for keyword in keywords:
            # Scrape Amazon
            amazon_products = self.scrape_amazon_search(keyword, max_products=5)
            all_products.extend(amazon_products)
            
            time.sleep(REQUEST_DELAY)  # Rate limiting
            
            # Scrape Flipkart
            flipkart_products = self.scrape_flipkart_search(keyword, max_products=5)
            all_products.extend(flipkart_products)
            
            time.sleep(REQUEST_DELAY)  # Rate limiting
        
        # Save to database
        if all_products:
            self.save_to_database(all_products)
            print(f"\n✅ Successfully scraped {len(all_products)} products")
        else:
            print("\n⚠️  No products found. Check selectors or network connection.")
        
        print(f"\nCompleted at: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
        print("=" * 60)
        
        # Cleanup
        self.cursor.close()
        self.conn.close()


if __name__ == "__main__":
    print("\n⚠️  IMPORTANT LEGAL NOTICE:")
    print("Web scraping may violate Terms of Service.")
    print("This script is for EDUCATIONAL purposes only.")
    print("For production, use official APIs:\n")
    print("  - Amazon Product Advertising API")
    print("  - Flipkart Affiliate API\n")
    
    try:
        scraper = LaptopScraper()
        scraper.run(keywords=['laptop'])
    except KeyboardInterrupt:
        print("\n\n⚠️  Scraping interrupted by user")
    except Exception as e:
        print(f"\n\n✗ Fatal error: {e}")
        sys.exit(1)