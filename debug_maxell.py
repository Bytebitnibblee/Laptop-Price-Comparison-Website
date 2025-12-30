#!/usr/bin/env python3
from bs4 import BeautifulSoup

def debug_maxell():
    print("Debugging Maxell selectors...")
    with open("maxell_page.html", "r", encoding="utf-8") as f:
        html = f.read()

    soup = BeautifulSoup(html, 'html.parser')
    products = soup.find_all("article", class_="w-grid-item")

    print(f"Found {len(products)} products")

    if products:
        product = products[0]
        print(f"Product classes: {product.get('class')}")

        # Debug title
        title_elem = product.find("div", class_="w-post-elm post_title")
        print(f"Title elem found: {title_elem is not None}")
        if title_elem:
            print(f"Title elem classes: {title_elem.get('class')}")
            a_tag = title_elem.find("a")
            print(f"A tag found: {a_tag is not None}")
            if a_tag:
                print(f"A tag text: '{a_tag.get_text(strip=True)[:50]}...'")
                print(f"A tag href: {a_tag.get('href')}")

        # Debug price
        price_field = product.find("p", class_="w-post-elm product_field price")
        print(f"Price field found: {price_field is not None}")
        if price_field:
            print(f"Price field classes: {price_field.get('class')}")
            price_elem = price_field.find("span", class_="woocommerce-Price-amount")
            print(f"Price elem found: {price_elem is not None}")
            if price_elem:
                print(f"Price elem: {price_elem}")
                bdi_elem = price_elem.find("bdi")
                print(f"BDI elem found: {bdi_elem is not None}")
                if bdi_elem:
                    print(f"BDI text: '{bdi_elem.get_text(strip=True)}'")

if __name__ == "__main__":
    debug_maxell()





