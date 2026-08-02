#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <PubSubClient.h>
#include <WiFiClientSecure.h>
#include <HX711_ADC.h>
#include <Wire.h>
#include <LiquidCrystal_I2C.h>

// Konfigurasi WiFi
const char* WIFI_SSID = "";
const char* WIFI_PASS = "";

// Konfigurasi Server
const char* URL_DATA    = "https://non.stress.test.diagtem.xyz/api/data.php";
const char* URL_SESSION = "https://non.stress.test.diagtem.xyz/api/session.php";

// Konfigurasi MQTT
const char* MQTT_HOST   = "60bade7c84b848c3b1ff035a19f5613f.s1.eu.hivemq.cloud";
const int   MQTT_PORT   = 8883;
const char* MQTT_USER   = "nonstresstest";
const char* MQTT_PASS   = "Nonstresstest2026";
const char* MQTT_CLIENT = "esp32_toco";
const char* TOPIC_TOCO  = "nst/toco";

WiFiClientSecure secureClient;
PubSubClient     mqttClient(secureClient);

// Pin HX711
const int HX711_DT  = 2;
const int HX711_SCK = 1;

// Pin Tombol
const int buttonPin = D2;
const int pinStart  = D10;
const int pinStop   = D9;
const int pinReset  = D8;

// Sensor & LCD
HX711_ADC         scale(HX711_DT, HX711_SCK);
LiquidCrystal_I2C lcd(0x27, 20, 4);

float calibration_factor = 2235.02;

// State Sesi
enum SessionState { IDLE, RUNNING, STOPPED };
volatile SessionState sessionState = IDLE;
bool isRecording     = false;
int  activeSessionId = 0;

// State Serial1
String        serialBuffer = "";
int           fhrBpm       = 0;
String        fhrStatus    = "NoSignal";
unsigned long lastFhrMs    = 0;
const unsigned long FHR_TIMEOUT_MS = 3000;

// State Tombol Bookmark
volatile bool buttonPressed             = false;
volatile unsigned long lastInterruptTime = 0;
const unsigned long debounceDelay       = 800; 

int           nilaiGrafik       = 0;
unsigned long waktuTombolDitekan = 0;
const unsigned long durasiGrafik = 1000;

// ISR Tombol Start / Stop / Reset
volatile bool startPressed = false, stopPressed = false, resetPressed = false;
volatile unsigned long lastStartISR = 0, lastStopISR = 0, lastResetISR = 0;
const unsigned long debounceSSR = 300;

void IRAM_ATTR isrStart() {
  unsigned long t = millis();
  if (t - lastStartISR > debounceSSR) { startPressed = true; lastStartISR = t; }
}
void IRAM_ATTR isrStop() {
  unsigned long t = millis();
  if (t - lastStopISR > debounceSSR) { stopPressed = true; lastStopISR = t; }
}
void IRAM_ATTR isrReset() {
  unsigned long t = millis();
  if (t - lastResetISR > debounceSSR) { resetPressed = true; lastResetISR = t; }
}
void IRAM_ATTR handleButtonPress() {
  unsigned long t = millis();
  if (t - lastInterruptTime > 800) {
    buttonPressed = true;
    lastInterruptTime = t;
  }
}

// Struktur Paket
struct PaketMQTT { int toco; int bookmark; };
struct PaketHTTP { int toco; int bookmark; };
struct PaketSesi { char action[8]; };

// Queue & Mutex
QueueHandle_t     mqttQueue;
QueueHandle_t     mqttBmQueue;
QueueHandle_t     httpQueue;
QueueHandle_t     sesiQueue;
SemaphoreHandle_t lcdMutex;

// TASK MQTT
void taskMQTT(void* param) {
  PaketMQTT paket;
  for (;;) {
    if (WiFi.status() == WL_CONNECTED) {
      if (!mqttClient.connected()) {
        int tries = 0;
        while (!mqttClient.connected() && tries < 5) {
          tries++;
          Serial.printf("[MQTT] Konek... (percobaan %d)\n", tries);
          if (mqttClient.connect(MQTT_CLIENT, MQTT_USER, MQTT_PASS)) {
            Serial.println("[MQTT] Terhubung ke HiveMQ!");
          } else {
            Serial.printf("[MQTT] Gagal, rc=%d\n", mqttClient.state());
            vTaskDelay(2000 / portTICK_PERIOD_MS);
          }
        }
      }
      mqttClient.loop();
    }

    if (xQueueReceive(mqttBmQueue, &paket, 0) == pdTRUE) {
      if (mqttClient.connected()) {
        char msg[64];
        snprintf(msg, sizeof(msg), "{\"toco\":%d,\"bookmark\":1}", paket.toco);
        bool ok = mqttClient.publish(TOPIC_TOCO, msg, false);
        Serial.printf("[MQTT→BM] %s %s\n", msg, ok ? "OK" : "FAIL");
      }
      continue;
    }

    if (xQueueReceive(mqttQueue, &paket, 5 / portTICK_PERIOD_MS) == pdTRUE) {
      if (mqttClient.connected()) {
        char msg[64];
        snprintf(msg, sizeof(msg), "{\"toco\":%d,\"bookmark\":0}", paket.toco);
        bool ok = mqttClient.publish(TOPIC_TOCO, msg, false);
        Serial.printf("[MQTT→] %s %s\n", msg, ok ? "OK" : "FAIL");
      }
    }
  }
}

// TASK HTTP
void taskHTTP(void* param) {
  PaketHTTP paket;
  for (;;) {
    if (xQueueReceive(httpQueue, &paket, portMAX_DELAY) == pdTRUE) {

      if (WiFi.status() != WL_CONNECTED) {
        WiFi.begin(WIFI_SSID, WIFI_PASS);
        int tries = 0;
        while (WiFi.status() != WL_CONNECTED && tries < 20) {
          vTaskDelay(500 / portTICK_PERIOD_MS); tries++;
        }
      }
      if (WiFi.status() != WL_CONNECTED) continue;

      HTTPClient http;
      http.begin(URL_DATA);
      http.addHeader("Content-Type", "application/json");
      http.setTimeout(4000);
      String body = "{\"sensor\":\"TOCO\",\"toco\":"
                  + String(paket.toco)
                  + ",\"bookmark\":" + String(paket.bookmark) + "}";
      int code = http.POST(body);
      Serial.printf("[HTTP→DB] toco=%d bm=%d | code=%d\n",
                    paket.toco, paket.bookmark, code);
      http.end();
    }
  }
}

// TASK SESI
void taskSesi(void* param) {
  PaketSesi paket;
  for (;;) {
    if (xQueueReceive(sesiQueue, &paket, portMAX_DELAY) == pdTRUE) {

      Serial.printf("[SESI] Proses aksi: %s\n", paket.action);

      if (WiFi.status() != WL_CONNECTED) {
        WiFi.begin(WIFI_SSID, WIFI_PASS);
        int tries = 0;
        while (WiFi.status() != WL_CONNECTED && tries < 20) {
          vTaskDelay(500 / portTICK_PERIOD_MS); tries++;
        }
      }
      if (WiFi.status() != WL_CONNECTED) {
        Serial.printf("[SESI] WiFi gagal, aksi '%s' tidak terkirim!\n", paket.action);
        continue;
      }

      int code = -1;
      for (int attempt = 1; attempt <= 3 && code != 200; attempt++) {
        HTTPClient http;
        http.begin(URL_SESSION);
        http.addHeader("Content-Type", "application/json");
        http.setTimeout(5000);
        String body = "{\"action\":\"" + String(paket.action) + "\"}";
        code = http.POST(body);

        if (code == 200) {
          String resp = http.getString();
          Serial.printf("[SESI] %s berhasil → %s\n", paket.action, resp.c_str());

          StaticJsonDocument<256> doc;
          if (!deserializeJson(doc, resp)) {
            const char* status = doc["status"] | "";
            if (strcmp(paket.action, "start") == 0 &&
                (strcmp(status, "ok") == 0 || strcmp(status, "already_recording") == 0)) {
              isRecording     = true;
              activeSessionId = doc["session_id"] | 0;
              Serial.printf("[SESI] Recording aktif, sesi #%d\n", activeSessionId);
            } else if (strcmp(paket.action, "stop") == 0 || strcmp(paket.action, "reset") == 0) {
              isRecording     = false;
              activeSessionId = 0;
            }
          }
        } else {
          Serial.printf("[SESI] Percobaan %d/%d gagal, HTTP %d — coba lagi...\n",
                        attempt, 3, code);
          vTaskDelay(500 / portTICK_PERIOD_MS);
        }
        http.end();
      }

      if (code != 200) {
        Serial.printf("[SESI] GAGAL setelah 3x percobaan untuk aksi '%s'\n", paket.action);
      }
    }
  }
}

// Helper
void kirimData(int toco, int bookmark) {
  PaketMQTT pm; pm.toco = toco; pm.bookmark = bookmark;

  if (bookmark == 1) {
    if (xQueueSend(mqttBmQueue, &pm, 50 / portTICK_PERIOD_MS) != pdTRUE) {
      Serial.println("[BM] Queue bookmark penuh, bookmark dibuang!");
    } else {
      Serial.println("[BM] Bookmark masuk queue MQTT!");
    }
  } else {
    xQueueSend(mqttQueue, &pm, 0);
  }

  PaketHTTP ph; ph.toco = toco; ph.bookmark = bookmark;
  xQueueSend(httpQueue, &ph, 0);
}

void kirimSesi(const char* action) {
  PaketSesi ps;
  strncpy(ps.action, action, sizeof(ps.action));
  if (xQueueSend(sesiQueue, &ps, 100 / portTICK_PERIOD_MS) != pdTRUE) {
    Serial.printf("[SESI] Queue penuh, aksi '%s' dibuang!\n", action);
  }
}

float gramToIndeksKontraksi(float gram) {
  if (gram <= 0) return 20.0;
  return 20.0 + (gram / 2.5);
}

void bacaSerialFHR() {
  while (Serial1.available()) {
    char c = (char)Serial1.read();
    if (c == '\n') {
      serialBuffer.trim();
      int commaIdx = serialBuffer.indexOf(',');
      if (commaIdx > 0) {
        int    bpm  = serialBuffer.substring(0, commaIdx).toInt();
        String stat = serialBuffer.substring(commaIdx + 1);
        stat.trim();
        if (bpm == 0 || (bpm >= 50 && bpm <= 250)) {
          fhrBpm    = bpm;
          fhrStatus = stat;
          lastFhrMs = millis();
          if (xSemaphoreTake(lcdMutex, 10) == pdTRUE) {
            char buf0[21], buf1[21];
            snprintf(buf0, sizeof(buf0), "FHR  : %3d bpm      ", fhrBpm);
            snprintf(buf1, sizeof(buf1), "Status: %-12s", fhrStatus.c_str());
            lcd.setCursor(0, 0); lcd.print(buf0);
            lcd.setCursor(0, 1); lcd.print(buf1);
            xSemaphoreGive(lcdMutex);
          }
        }
      }
      serialBuffer = "";
    } else {
      if (serialBuffer.length() < 32) serialBuffer += c;
    }
  }

  if (fhrBpm > 0 && (millis() - lastFhrMs) > FHR_TIMEOUT_MS) {
    fhrBpm    = 0;
    fhrStatus = "NoSignal";
    if (xSemaphoreTake(lcdMutex, 10) == pdTRUE) {
      lcd.setCursor(0, 0); lcd.print("FHR  : --- (offline)");
      lcd.setCursor(0, 1); lcd.print("Status: NoSignal    ");
      xSemaphoreGive(lcdMutex);
    }
  }
}

// Interpretasi TOCO
void interpretTOCO(float indeks) {
  if      (indeks < 21) Serial.print("Tidak Ada/BH");
  else if (indeks < 25) Serial.print("Hipotonik");
  else if (indeks < 80) Serial.print("Normal");
  else                  Serial.print("Hipertonik");
}

// Koneksi WiFi
void connectWiFi() {
  Serial.printf("[WiFi] Menghubungkan ke %s...\n", WIFI_SSID);
  WiFi.begin(WIFI_SSID, WIFI_PASS);
  int tries = 0;
  while (WiFi.status() != WL_CONNECTED && tries < 20) {
    delay(500); Serial.print("."); tries++;
  }
  if (WiFi.status() == WL_CONNECTED)
    Serial.printf("\n[WiFi] OK! IP: %s\n", WiFi.localIP().toString().c_str());
  else
    Serial.println("\n[WiFi] GAGAL — lanjut tanpa WiFi.");
}

//  SETUP
void setup() {
  Serial.begin(115200);
  Wire.begin(D4, D5);
  lcd.init();
  lcd.backlight();
  lcd.clear();
  lcd.setCursor(0, 0);
  lcd.print("Inisialisasi...");
  delay(2000);

  // Tombol
  pinMode(buttonPin, INPUT_PULLUP);
  attachInterrupt(digitalPinToInterrupt(buttonPin), handleButtonPress, FALLING);
  pinMode(pinStart, INPUT_PULLUP);
  pinMode(pinStop,  INPUT_PULLUP);
  pinMode(pinReset, INPUT_PULLUP);
  attachInterrupt(digitalPinToInterrupt(pinStart), isrStart, FALLING);
  attachInterrupt(digitalPinToInterrupt(pinStop),  isrStop,  FALLING);
  attachInterrupt(digitalPinToInterrupt(pinReset), isrReset, FALLING);

  // HX711
  scale.begin();
  lcd.clear();
  lcd.setCursor(0, 0); lcd.print("Tunggu stabilisasi..");
  Serial.println("Tunggu stabilisasi..");
  scale.start(3000, true);
  if (scale.getTareTimeoutFlag() || scale.getSignalTimeoutFlag()) {
    Serial.println("Timeout! Cek wiring HX711");
    lcd.setCursor(0, 1); lcd.print("ERROR: Cek wiring!");
    while (1);
  }
  scale.setCalFactor(calibration_factor);
  Serial.println("Tare selesai!");
  lcd.setCursor(0, 1); lcd.print("Tare Selesai!");
  delay(2000);
  lcd.clear();

  // Serial1
  Serial1.begin(9600, SERIAL_8N1, D7, -1);

  // WiFi
  lcd.setCursor(0, 2); lcd.print("Konek WiFi...       ");
  connectWiFi();
  delay(1000);
  lcd.clear();

  // MQTT
  secureClient.setInsecure();
  mqttClient.setServer(MQTT_HOST, MQTT_PORT);
  mqttClient.setBufferSize(256);

  // Placeholder LCD
  lcd.setCursor(0, 0); lcd.print("FHR  : --- (offline)");
  lcd.setCursor(0, 1); lcd.print("Status: Menunggu... ");
  lcd.setCursor(0, 2); lcd.print("Kontraksi : 20.00   ");
  lcd.setCursor(0, 3); lcd.print("Tidak Ada/BH        ");

  // Mutex & Queue
  lcdMutex   = xSemaphoreCreateMutex();
  mqttQueue  = xQueueCreate(3, sizeof(PaketMQTT));  // data TOCO biasa — buang jika penuh
  mqttBmQueue= xQueueCreate(5, sizeof(PaketMQTT));  // bookmark — jangan sampai dibuang
  httpQueue  = xQueueCreate(5, sizeof(PaketHTTP));
  sesiQueue  = xQueueCreate(5, sizeof(PaketSesi));

  // Task MQTT
  xTaskCreatePinnedToCore(taskMQTT, "taskMQTT", 8192, NULL, 2, NULL, 0);

  // Task HTTP data
  xTaskCreatePinnedToCore(taskHTTP, "taskHTTP", 8192, NULL, 1, NULL, 0);

  // Task Sesi
  xTaskCreatePinnedToCore(taskSesi, "taskSesi", 8192, NULL, 3, NULL, 0);
}

// LOOP
void loop() {
  bacaSerialFHR();

  unsigned long waktuSekarang = millis();

  // Tombol Start
  if (startPressed) {
    startPressed = false;
    sessionState = RUNNING;
    Serial.println("[START] Pengukuran dimulai");
    kirimSesi("start");
  }

  // Tombol Stop
  if (stopPressed) {
    stopPressed = false;
    sessionState = STOPPED;
    Serial.println("[STOP] Pengukuran dihentikan");
    kirimSesi("stop");
  }

  // Tombol Reset
  if (resetPressed) {
    resetPressed = false;
    sessionState = IDLE;
    nilaiGrafik  = 0;
    scale.tareNoDelay();
    lcd.clear();
    lcd.setCursor(0, 1); lcd.print("Reset & Tare ulang  ");
    Serial.println("[RESET] Pengukuran direset, tare ulang");
    kirimSesi("reset");
    delay(1000);
  }

  // Tombol Bookmark
  if (buttonPressed) {
    buttonPressed      = false;
    nilaiGrafik        = 250;
    waktuTombolDitekan = waktuSekarang;
  }
  if (nilaiGrafik == 250 && (waktuSekarang - waktuTombolDitekan >= durasiGrafik)) {
    nilaiGrafik = 0;
  }

  // Baca HX711
  if (scale.update()) {
    float weight = scale.getData();

    float indeks  = gramToIndeksKontraksi(weight);
    int   tocoInt = (int)round(indeks);
    int   bmSend  = (nilaiGrafik > 0) ? 1 : 0;

    // Serial Plotter
    Serial.print("Indeks_Kontraksi:"); Serial.print(indeks);
    Serial.print(",Indikator:");       Serial.println(nilaiGrafik);

    // Serial Monitor
    Serial.printf("Berat: %.2f g | Kontraksi: %.2f | ", weight, indeks);
    interpretTOCO(indeks);
    Serial.println();

    // LCD
    if (xSemaphoreTake(lcdMutex, 10) == pdTRUE) {
      char buf2[21];
      snprintf(buf2, sizeof(buf2), "Kontraksi : %-6.2f  ", indeks);
      lcd.setCursor(0, 2); lcd.print(buf2);
      lcd.setCursor(0, 3);
      if      (indeks < 21) lcd.print("Tidak Ada/BH       ");
      else if (indeks < 25) lcd.print("Hipotonik          ");
      else if (indeks < 80) lcd.print("Normal             ");
      else                  lcd.print("Hipertonik         ");
      xSemaphoreGive(lcdMutex);
    }

    kirimData(tocoInt, bmSend);
  }
}
