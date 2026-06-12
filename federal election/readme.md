# Nepal PR Election Results Dashboard

A simple web dashboard that displays **Proportional Representation (PR) election results** from the Election Commission of Nepal.

The application fetches vote data, calculates estimated PR seats, and displays results in a clean editorial-style interface.

---

## Features

- Fetches live PR vote data from the Election Commission
- Calculates **estimated proportional seats (110 PR seats)**
- Clean editorial-style UI
- Vote share visualization with progress bars
- Automatic vote percentage calculation
- Backend proxy to bypass CORS restrictions

---

## Tech Stack

Frontend:

- HTML
- CSS
- JavaScript

Backend:

- PHP (used as a proxy to access the Election Commission API)

Environment:

- XAMPP / Apache

---

## Project Structure

```
project-folder
│
├── index.html          # Main frontend UI
│
└── api
    └── results.php     # Backend proxy to fetch election data
```

---

## How It Works

1. The browser cannot directly access the Election Commission API due to **CORS restrictions**.

2. A small PHP backend (`results.php`) acts as a **proxy**:

```
Browser → PHP Proxy → election.gov.np API
```

3. The frontend fetches data from the proxy and then:

- Calculates vote share
- Estimates seats
- Renders the UI

---

## Seat Calculation Method

Nepal's House of Representatives has **110 PR seats**.

Seats are estimated using proportional distribution:

```
seat = round((partyVotes / totalVotes) * 110)
```

This provides an **approximate seat distribution** based on vote share.

---

## Installation

### 1. Clone or download the project

```
git clone https://github.com/yourusername/nepal-pr-dashboard.git
```

or download the ZIP and extract it.

---

### 2. Move the project to your server directory

For XAMPP:

```
xampp/htdocs/project-folder
```

---

### 3. Start Apache

Open XAMPP Control Panel and start:

```
Apache
```

---

### 4. Open the project

Visit:

```
http://localhost/project-folder/
```

---

## API Source

Election Commission of Nepal:

```
https://result.election.gov.np
```

Data file used:

```
PRHoRPartyTop5.txt
```

---

## Possible Improvements

Future enhancements could include:

- Real-time auto refresh
- Charts (Chart.js / D3.js)
- PR seat allocation using official divisor methods
- Geographic visualization of results
- Mobile responsive layout
- Deployment to a cloud server

---

## License

This project is intended for **educational and demonstration purposes**.
Election data belongs to the **Election Commission of Nepal**.
