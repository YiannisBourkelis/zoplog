# Network Logger Application

This project is designed to log network traffic and block ads and unwanted content. It consists of a Python-based logger that captures HTTP/HTTPS requests and a PHP-based web interface for displaying the logged data.

## Project Structure

```
network-logger
├── python-logger
│   ├── logger.py          # Main logic for logging HTTP/HTTPS requests
│   └── requirements.txt   # Python dependencies for the logger
├── web-interface
│   ├── index.php          # Entry point for the web interface
│   ├── config.php         # Configuration settings for the PHP application
└── README.md              # Project documentation
```

## Setup Instructions

### Python Logger

1. Navigate to the `python-logger` directory.
2. Install the required dependencies using pip:
   ```
   pip install -r requirements.txt
   ```
3. Run the logger script:
   ```
   python logger.py
   ```

Install the required dependencies

For Ubuntu/Debian, run:
```
sudo apt update
sudo apt install python3 python3-pip python3-scapy python3-
```

### PHP Web Interface

1. Ensure you have a web server with PHP support (e.g., Apache, Nginx).
2. Place the `web-interface` directory in your web server's root directory.
3. Update the `config.php` file with your database connection details.
4. Access the web interface by navigating to `http://your-server-address/php-web-interface/index.php`.

## Usage

- The Python logger will start capturing network traffic and logging the requests to the  database.
- Use the PHP web interface to view the logged data and manage unwanted content.

## Contributing

Feel free to submit issues or pull requests to improve the project.

## Database schema for blocklists (web-interface)

Run these migrations in MariaDB/MySQL to create or align the tables used by the Block Lists UI:

```
CREATE TABLE IF NOT EXISTS blocklists (
   id INT AUTO_INCREMENT PRIMARY KEY,
   url TEXT NOT NULL,
   description TEXT NULL,
   category ENUM('adware','malware','phishing','cryptomining','tracking','scam','fakenews','gambling','social','porn','streaming','proxyvpn','shopping','hate','other') NOT NULL,
   active ENUM('active','inactive') NOT NULL DEFAULT 'active',
   created_at DATETIME NOT NULL,
   updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS blocklist_domains (
   id INT AUTO_INCREMENT PRIMARY KEY,
   blocklist_id INT NOT NULL,
   domain VARCHAR(255) NOT NULL,
   CONSTRAINT fk_blocklist_domains_blocklists
      FOREIGN KEY (blocklist_id) REFERENCES blocklists(id)
      ON DELETE CASCADE,
   UNIQUE KEY uniq_blocklist_domain (blocklist_id, domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- If you previously had an INT active column, convert it to ENUM
ALTER TABLE blocklists MODIFY active ENUM('active','inactive') NOT NULL DEFAULT 'active';
```

Endpoints used by the web UI (in `web-interface/`):
- add_blocklist.php — downloads and stores a list plus its domains
- toggle_blocklist.php — sets active to 'active' or 'inactive'
- delete_blocklist.php — removes a blocklist and its domains