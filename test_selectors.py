#!/usr/bin/env python3
"""
Test script to check if the updated selectors work for Daraz, Nagmani, and Maxell
"""
from bs4 import BeautifulSoup

def test_daraz():
    print("Testing Daraz selectors...")
    with open("daraz_page.html", "r", encoding="utf-8") as f:
        html = f.read()

    soup = BeautifulSoup(html, 'html.parser')
    products = soup.find_all("div", class_="Bm3ON")

    print(f"Found {len(products)} products")

    if products:
        product = products[0]
        # Test title
        title_elem = product.find("div", class_="RfADt")
        title = None
        if title_elem:
            a_tag = title_elem.find("a")
            if a_tag and "title" in a_tag.attrs:
                title = a_tag["title"]

        # Test price
        price_elem = product.find("div", class_="aBrP0")
        price = None
        if price_elem:
            span_elem = price_elem.find("span", class_="ooOxS")
            if span_elem:
                price = span_elem.get_text(strip=True)

        print(f"Sample: Title='{title[:50] if title else None}...', Price='{price}'")

def test_nagmani():
    print("\nTesting Nagmani selectors...")
    with open("nagmani_page.html", "r", encoding="utf-8") as f:
        html = f.read()

    soup = BeautifulSoup(html, 'html.parser')
    products = soup.find_all("li", class_="item product product-item")

    print(f"Found {len(products)} products")

    if products:
        product = products[0]
        # Test title
        title_elem = product.find("strong", class_="product name product-item-name")
        title = None
        if title_elem:
            a_tag = title_elem.find("a")
            if a_tag:
                title = a_tag.get_text(strip=True)

        # Test price
        price_box = product.find("div", class_="price-box price-final_price")
        price = None
        if price_box:
            span_elem = price_box.find("span", class_="price")
            if span_elem:
                price = span_elem.get_text(strip=True)

        print(f"Sample: Title='{title[:50] if title else None}...', Price='{price}'")

def test_maxell():
    print("\nTesting Maxell selectors...")
    with open("maxell_page.html", "r", encoding="utf-8") as f:
        html = f.read()

    soup = BeautifulSoup(html, 'html.parser')
    products = soup.find_all("article", class_="w-grid-item")

    print(f"Found {len(products)} products")

    if products:
        product = products[0]
        # Test title
        title_elem = product.find("div", class_="w-post-elm post_title")
        title = None
        if title_elem:
            a_tag = title_elem.find("a")
            if a_tag:
                title = a_tag.get_text(strip=True)

        # Test price
        price_field = product.find("p", class_="w-post-elm product_field price")
        price = None
        if price_field:
            price_elem = price_field.find("span", class_="woocommerce-Price-amount")
            if price_elem:
                bdi_elem = price_elem.find("bdi")
                if bdi_elem:
                    price = bdi_elem.get_text(strip=True)

        print(f"Sample: Title='{title[:50] if title else None}...', Price='{price}'")

if __name__ == "__main__":
    test_daraz()
    test_nagmani()
    test_maxell()





