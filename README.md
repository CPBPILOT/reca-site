# DOJ RECA – Manhattan Project Waste Tracker

A simple web app that scrapes and visualizes publicly available data from the U.S. Department of Justice (DOJ) **Radiation Exposure Compensation Act (RECA)** site.  
The project focuses on **Manhattan Project waste site claims** — making it easier to see trends in pending, approved, and denied claims over time.

---

## ✨ Features

- 📊 **Interactive Chart & Table** – See how claims evolve by date, with totals for pending, approved, and denied cases.
- 📅 **Daily Scraper** – The backend script automatically pulls fresh data and updates the dataset.
- 🔍 **Historical Tracking** – Changes are merged into an ongoing dataset so you can look back over time.
- 📥 **Export Options** – Data can be downloaded as JSON or CSV for further analysis.
- 🔒 **Privacy First** – No accounts, no ads, no trackers.

---

## 🚀 Live Site

The site is hosted here:  
👉 [**Visit the Manhattan Project Waste Tracker**](reca.bourque.io)  


---

## 🛠️ How It Works

1. **Scraper (`scrape.php`)**  
   - Fetches the latest claim data from the DOJ RECA site.  
   - Updates `data.json` with new entries.  
   - Handles duplicates by merging or archiving old entries.

2. **Frontend (`index.html` + Chart.js)**  
   - Displays the dataset as a chart and sortable table.  
   - Latest dates appear at the top of the table for quick reference.

3. **Deployment**  
   - Runs in Docker with PHP-FPM + Nginx (or any standard PHP hosting).  
   - Designed to be lightweight and maintenance-free.

---

