# GPS Trip Processor

A simple tool to process GPS trip data and generate GeoJSON outputs.

## Features

- Processes GPS trip data files
- Generates `trips.geojson` with valid trip records
- Logs rejected or invalid entries in `rejects.log`

## Installation

1. Clone the repository:
   ```bash
   git clone <repository-url>
   ```

## Usage

1. Run the script:

```bash
php script.php
```

2. After running, open trips.geojson in a GeoJSON viewer or map application:

Online viewers:

https://geojson.io

https://mapshaper.org

Local: Open the trips.geojson file in any compatible GIS software like QGIS.
