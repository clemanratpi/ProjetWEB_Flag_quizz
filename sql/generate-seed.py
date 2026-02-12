import json, urllib.request

URL = "https://restcountries.com/v3.1/all?fields=name,cca2,cca3,translations"

def safe_sql(s: str) -> str:
    if s is None:
        return ""
    return str(s).replace("'", "''").strip()

with urllib.request.urlopen(URL) as r:
    countries = json.loads(r.read().decode("utf-8"))

rows = []
for c in countries:
    iso2 = (c.get("cca2") or "").upper()
    iso3 = (c.get("cca3") or "").upper()
    if len(iso2) != 2 or len(iso3) != 3:
        continue

    name_en = c.get("name", {}).get("common") or ""
    name_fr = c.get("translations", {}).get("fra", {}).get("common") or name_en

    flag_url = f"https://flagcdn.com/w320/{iso2.lower()}.png"

    rows.append((
        safe_sql(name_fr),
        safe_sql(name_en),
        iso2,
        iso3,
        flag_url
    ))

# tri pour fichier stable
rows.sort(key=lambda x: x[2])

print("INSERT INTO countries (name_fr, name_en, iso2, iso3, flag_url) VALUES")
for i, (fr, en, iso2, iso3, url) in enumerate(rows):
    suffix = "," if i < len(rows) - 1 else ""
    print(f"('{fr}','{en}','{iso2}','{iso3}','{url}'){suffix}")
print("ON CONFLICT (iso2) DO NOTHING;")
