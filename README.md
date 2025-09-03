# Network Logger Application

This project is designed to log network traffic and block unwanted content based on user defined block ists. It consists of a Python-based logger that captures HTTP/HTTPS requests and a PHP-based web interface for displaying the logged data.

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

- The Python logger will start capturing network traffic and logging the requests to the database.
- Use the PHP web interface to view the logged data and manage unwanted content.
- Access the real-time dashboard for live monitoring and analytics.

## Features

- **Real-time Network Monitoring**: Live dashboard with 2-second updates
- **Traffic Analysis**: Detailed breakdown of allowed vs blocked requests
- **System Resource Monitoring**: CPU, memory, disk, and network usage tracking
- **Interactive Charts**: Visual representation of traffic patterns and system metrics
- **Blocklist Management**: Web interface for managing content filters
- **NFTables Integration**: Advanced firewall rule management
- **RESTful API**: Centralized data access through `/api/realtime_data.php`

## Contributing

We welcome contributions to ZopLog! Please see our [Contributing Guidelines](CONTRIBUTING.md) for details on:

- How to submit issues and pull requests
- Code standards and best practices
- Copyright assignment requirements
- Security vulnerability reporting

## License

This project is licensed under the Apache License 2.0 - see the [LICENSE](LICENSE) file for details.

### Copyright Notice

Copyright 2025 Yiannis Bourkelis. All contributors assign their rights to the project maintainer.

## Support

- Report bugs and request features through GitHub Issues
- For security vulnerabilities, please contact the maintainer directly
- Documentation and setup help available in the project wiki