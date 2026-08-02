# 🏥 NST Monitoring System
### Non-Stress Test (Kardiotokografi) — Real-time Web Dashboard

## Tentang Project
Sistem monitoring janin berbasis IoT yang dirancang untuk kebutuhan klinis di rumah sakit.
Dikembangkan sebagai **Tugas Akhir DIII Teknik Elektromedik** — Poltekkes Kemenkes Surabaya.

## Teknologi yang Digunakan
| Komponen | Teknologi |
|----------|-----------|
| **Sensor Doppler** | Piezoelectric (deteksi DJJ / detak jantung janin) |
| **Sensor Toco** | Load Cell + HX711 (deteksi kontraksi uterus) |
| **Wireless** | WiFi + MQTT (HiveMQ broker) |
| **Mikrokontroler** | ESP32 |
| **Backend** | Python |
| **Frontend** | HTML, JavaScript |
| **Database** | MySQL |
| **Hosting** | Domainesia |

## Fitur Utama
- ✅ Monitoring DJJ & kontraksi uterus secara real-time
- ✅ Transmisi wireless via WiFi + protokol MQTT
- ✅ Web dashboard yang responsif
- ✅ Manajemen data pasien (MySQL)
- ✅ Monitoring sesuai standar klinik: 20–30 menit
- ✅ Divalidasi terhadap alat CTG komersial dan alat kalibrasi

## Validasi
Diuji pada ibu hamil trimester ketiga dan dibandingkan langsung dengan:
- Alat kalibrasi
- Alat CTG komersial

## Struktur Project
```
nst-fetal-monitoring/
├── backend/          → File Python & database schema
├── frontend/         → File HTML, JavaScript, CSS
├── hardware/         → Firmware ESP32 (.ino)
├── docs/             → Dokumentasi teknis
└── images/           → Foto alat & screenshot dashboard
```

## Cara Menjalankan
1. Clone atau download repository ini
2. Install dependencies: `pip install -r requirements.txt`
3. Setup database: import file `schema.sql` ke MySQL
4. Jalankan backend: `python app.py`
5. Buka browser, akses dashboard di `localhost:5000`

## Informasi Akademik
**Tugas Akhir (Capstone Project)**
Diploma III Teknik Elektromedik
Poltekkes Kemenkes Surabaya | Agustus 2025 – Mei 2026

## Kontak
**Pembuat:** Muhammad Wildan Izzulhaq
**Email:** wildanizzul13@gmail.com
**GitHub:** github.com/wildan-izzulhaq
**LinkedIn:** linkedin.com/in/

## Lisensi
MIT License
