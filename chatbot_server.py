import json
import os
import re

from flask import Flask, jsonify, request
import google.generativeai as genai
import mysql.connector
from dotenv import load_dotenv

load_dotenv()

app = Flask(__name__)
app.config["MAX_CONTENT_LENGTH"] = 4096

FILTER_PROMPT = """Extract property-search filters from the user's question.
Return only a JSON object with these optional keys:
location (short string), property_type (Sale or Rental), min_price (number),
max_price (number), bedrooms (integer), bathrooms (integer),
pool_available (Yes or No), is_dog_friendly (Yes or No).
Do not include SQL, explanations, markdown, or any other keys.
Use an empty object when no supported filter can be extracted.
User question: {question}
"""


def extract_filters(question):
    api_key = os.getenv("GEMINI_API_KEY")
    if not api_key:
        raise RuntimeError("The chatbot service is not configured.")

    genai.configure(api_key=api_key)
    model = genai.GenerativeModel("gemini-1.5-pro")
    response = model.generate_content(
        FILTER_PROMPT.format(question=question),
        generation_config={"temperature": 0.0, "response_mime_type": "application/json"},
    )
    filters = json.loads(response.text)
    if not isinstance(filters, dict):
        raise ValueError("Invalid search filters.")

    allowed_keys = {
        "location", "property_type", "min_price", "max_price", "bedrooms",
        "bathrooms", "pool_available", "is_dog_friendly",
    }
    return {key: value for key, value in filters.items() if key in allowed_keys}


def bounded_number(value, minimum, maximum, integer=False):
    if isinstance(value, bool) or not isinstance(value, (int, float)):
        return None
    if value < minimum or value > maximum or (integer and not float(value).is_integer()):
        return None
    return int(value) if integer else float(value)


def search_properties(filters):
    conditions = ["status = 'available'"]
    values = []

    location = filters.get("location")
    if isinstance(location, str):
        location = re.sub(r"[%_\\\\]", "", location).strip()[:80]
        if location:
            conditions.append("location LIKE %s")
            values.append(f"%{location}%")

    property_type = filters.get("property_type")
    if property_type in ("Sale", "Rental"):
        conditions.append("property_type = %s")
        values.append(property_type)

    for key, column in (("min_price", "price"), ("max_price", "price"),
                         ("bedrooms", "bedrooms"), ("bathrooms", "bathrooms")):
        if key in filters:
            number = bounded_number(filters[key], 0, 1000000000 if "price" in key else 50,
                                    integer=key in ("bedrooms", "bathrooms"))
            if number is not None:
                operator = ">=" if key == "min_price" else "<=" if key == "max_price" else "="
                conditions.append(f"{column} {operator} %s")
                values.append(number)

    for key, column in (("pool_available", "pool_available"),
                         ("is_dog_friendly", "is_dog_friendly")):
        if filters.get(key) in ("Yes", "No"):
            conditions.append(f"{column} = %s")
            values.append(filters[key])

    query = (
        "SELECT property_id, title, price, location, property_type, bedrooms, bathrooms "
        "FROM property_listings WHERE " + " AND ".join(conditions) +
        " ORDER BY created_at DESC LIMIT 10"
    )
    connection = mysql.connector.connect(
        host=os.getenv("DB_HOST", "localhost"),
        user=os.getenv("DB_USER", "root"),
        password=os.getenv("DB_PASSWORD", ""),
        database=os.getenv("DB_NAME", "realestatephp"),
        connection_timeout=5,
    )
    try:
        cursor = connection.cursor(dictionary=True)
        try:
            cursor.execute(query, values)
            return cursor.fetchall()
        finally:
            cursor.close()
    finally:
        connection.close()


@app.route("/chatbot", methods=["POST"])
def chatbot():
    data = request.get_json(silent=True)
    question = data.get("question", "").strip() if isinstance(data, dict) else ""
    if not question:
        return jsonify({"answer": "Please ask a property-search question."}), 400
    if len(question) > 500:
        return jsonify({"answer": "Please keep the question under 500 characters."}), 400

    try:
        filters = extract_filters(question)
        properties = search_properties(filters)
    except (ValueError, json.JSONDecodeError):
        app.logger.warning("Chatbot returned invalid filter data.")
        return jsonify({"answer": "I couldn't interpret that search. Please try a simpler question."}), 400
    except Exception:
        app.logger.exception("Chatbot property search failed.")
        return jsonify({"answer": "Property search is temporarily unavailable."}), 503

    if not properties:
        return jsonify({"answer": "No matching available properties were found."})

    summaries = [
        "{title} ({property_type}) for {price:,.2f} in {location}; {bedrooms} bedrooms, {bathrooms} bathrooms".format(
            title=item["title"],
            property_type=item["property_type"],
            price=float(item["price"]),
            location=item["location"] or "location not listed",
            bedrooms=item["bedrooms"] or 0,
            bathrooms=item["bathrooms"] or 0,
        )
        for item in properties
    ]
    return jsonify({"answer": "\n".join(summaries), "properties": properties})


if __name__ == "__main__":
    app.run(host="127.0.0.1", port=5000)
