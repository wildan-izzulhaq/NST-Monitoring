// ============================================================
//   FHR / BPM Monitor - XIAO ESP32-S3  (FINAL v11b + MQTT)
//   3-Task Architecture:
//   Core 1 : loop()    — proses sinyal ADC + hitung BPM
//   Core 0 : taskMQTT  — publish ke HiveMQ (prioritas tinggi)
//   Core 0 : taskHTTP  — POST ke data.php (boleh lambat)
//
//   MQTT dan HTTP berjalan di task TERPISAH sehingga
//   HTTP yang lambat tidak pernah memblokir MQTT.
// ============================================================

#include <algorithm>
#include <WiFi.h>
#include <HTTPClient.h>
#include <PubSubClient.h>
#include <WiFiClientSecure.h>

// ── Konfigurasi WiFi ─────────────────────────────────────────
const char* WIFI_SSID = "Urzeell";
const char* WIFI_PASS = "12345678901";

// ── Konfigurasi Server ───────────────────────────────────────
const char* URL_DATA = "https://non.stress.test.diagtem.xyz/api/data.php";

// ── Konfigurasi MQTT HiveMQ Cloud ───────────────────────────
const char* MQTT_HOST   = "60bade7c84b848c3b1ff035a19f5613f.s1.eu.hivemq.cloud";
const int   MQTT_PORT   = 8883;
const char* MQTT_USER   = "nonstresstest";
const char* MQTT_PASS   = "Nonstresstest2026";
const char* MQTT_CLIENT = "esp32_fhr";
const char* TOPIC_FHR   = "nst/fhr";

// ── Objek MQTT ───────────────────────────────────────────────
WiFiClientSecure secureClientFHR;
PubSubClient     mqttClientFHR(secureClientFHR);

// ── Interval kirim ───────────────────────────────────────────
const unsigned long SEND_INTERVAL_MS = 500;

// ── Pin & ADC ────────────────────────────────────────────────
#define ADC_PIN           A0
#define ADC_RESOLUTION    4095.0f
#define REF_VOLTAGE       3.3f
#define TIMER_PERIOD_US   250     // 4 kHz

// ── UART ke ESP32 TOCO ───────────────────────────────────────
#define SERIAL2_TX        D6
#define SERIAL2_RX        D7
#define SERIAL2_BAUD      9600

// ── Baseline & Deteksi ───────────────────────────────────────
#define BASELINE_ALPHA    0.00025f
#define WARMUP_SAMPLES    4000
#define SPIKE_RATIO       1.20f
#define REFRACTORY_MS     375

// ── Validasi BPM ─────────────────────────────────────────────
#define PEAK_MIN_INTERVAL 0.375f
#define PEAK_MAX_INTERVAL 1.0f
#define BPM_MIN           60.0f
#define BPM_MAX           160.0f
#define MIN_VALID_PEAKS   3
#define MAX_COV           0.35f
#define NO_SIGNAL_TIMEOUT 5000

// ── Klasifikasi DJJ ──────────────────────────────────────────
#define DJJ_BRADIKARDI_MAX  100.0f
#define DJJ_WASPADA_MAX     119.9f
#define DJJ_NORMAL_MAX      160.0f

// ── Filter Koefisien @ Fs = 4000 Hz ──────────────────────────
#define HB0   0.939062f
#define HB1  -1.878124f
#define HB2   0.939062f
#define HA1  -1.875174f
#define HA2   0.881074f

#define LB0_A  0.134582f
#define LB1_A  0.269163f
#define LB2_A  0.134582f
#define LA1_A -0.727726f
#define LA2_A  0.266053f

#define LB0_E  0.000007f
#define LB1_E  0.000014f
#define LB2_E  0.000007f
#define LA1_E -1.991006f
#define LA2_E  0.991034f

// ── State Filter ─────────────────────────────────────────────
float hx1=0,hx2=0,hy1=0,hy2=0;
float ax1=0,ax2=0,ay1=0,ay2=0;
float ex1=0,ex2=0,ey1=0,ey2=0;
float ex3=0,ex4=0,ey3=0,ey4=0;

// ── Baseline & Sinyal ────────────────────────────────────────
float baseline_ema  = 0;
bool  baseline_init = false;
int   warmup_count  = 0;

float envelope_signal = 0;
float stableBPM       = 0;
unsigned long stableBPMTime = 0;

bool  aboveThresh = false;
float peakHold    = 0;

unsigned long lastPeakTime = 0;
float peakIntervals[5]     = {0};
int   peakIdx              = 0;
int   validPeakCount       = 0;
int   peaksThisSecond      = 0;

// ── Timer ────────────────────────────────────────────────────
hw_timer_t   *sampleTimer = NULL;
volatile bool sampleFlag  = false;
void ARDUINO_ISR_ATTR onSampleTimer() { sampleFlag = true; }

// ── Struktur Paket ───────────────────────────────────────────
struct PaketMQTT_FHR { int bpm; };
struct PaketHTTP_FHR { int bpm; };

// ── Queue ────────────────────────────────────────────────────
QueueHandle_t mqttQueueFHR;
QueueHandle_t httpQueueFHR;

// ════════════════════════════════════════════════════════════
//   TASK MQTT FHR — Core 0, prioritas 2
//   Publish BPM ke HiveMQ secepat mungkin
// ════════════════════════════════════════════════════════════
void taskMQTT_FHR(void* param) {
  PaketMQTT_FHR paket;
  for (;;) {
    // Jaga koneksi MQTT
    if (WiFi.status() == WL_CONNECTED) {
      if (!mqttClientFHR.connected()) {
        int tries = 0;
        while (!mqttClientFHR.connected() && tries < 5) {
          tries++;
          Serial.printf("[MQTT-FHR] Konek... (percobaan %d)\n", tries);
          if (mqttClientFHR.connect(MQTT_CLIENT, MQTT_USER, MQTT_PASS)) {
            Serial.println("[MQTT-FHR] Terhubung ke HiveMQ!");
          } else {
            Serial.printf("[MQTT-FHR] Gagal, rc=%d\n", mqttClientFHR.state());
            vTaskDelay(2000 / portTICK_PERIOD_MS);
          }
        }
      }
      mqttClientFHR.loop();
    }

    // Ambil paket dan publish (timeout 5ms)
    if (xQueueReceive(mqttQueueFHR, &paket, 5 / portTICK_PERIOD_MS) == pdTRUE) {
      if (mqttClientFHR.connected()) {
        char msg[32];
        snprintf(msg, sizeof(msg), "{\"bpm\":%d}", paket.bpm);
        bool ok = mqttClientFHR.publish(TOPIC_FHR, msg, false);
        Serial.printf("[MQTT-FHR→] %s %s\n", msg, ok ? "OK" : "FAIL");
      }
    }
  }
}

// ════════════════════════════════════════════════════════════
//   TASK HTTP FHR — Core 0, prioritas 1
//   POST BPM ke data.php untuk disimpan ke database
// ════════════════════════════════════════════════════════════
void taskHTTP_FHR(void* param) {
  PaketHTTP_FHR paket;
  for (;;) {
    if (xQueueReceive(httpQueueFHR, &paket, portMAX_DELAY) == pdTRUE) {

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
      String body = "{\"sensor\":\"FHR\",\"bpm\":" + String(paket.bpm) + "}";
      int code = http.POST(body);
      Serial.printf("[HTTP-FHR→DB] bpm=%d | code=%d\n", paket.bpm, code);
      http.end();
    }
  }
}

// ── Helper: kirim BPM ke kedua queue (non-blocking) ──────────
void kirimFHR(int bpm) {
  PaketMQTT_FHR pm; pm.bpm = bpm;
  xQueueSend(mqttQueueFHR, &pm, 0);  // buang jika penuh

  PaketHTTP_FHR ph; ph.bpm = bpm;
  xQueueSend(httpQueueFHR, &ph, 0);
}

// ── Klasifikasi DJJ ──────────────────────────────────────────
String getDJJStatus(float bpm) {
  if (bpm <= 0)                   return "No Signal";
  if (bpm < DJJ_BRADIKARDI_MAX)  return "Bradikardi";
  if (bpm <= DJJ_WASPADA_MAX)    return "Waspada";
  if (bpm <= DJJ_NORMAL_MAX)     return "Normal";
  return "Takikardi";
}

// ════════════════════════════════════════════════════════════
//   SETUP
// ════════════════════════════════════════════════════════════
void setup() {
  Serial.begin(115200);
  delay(500);

  Serial1.begin(SERIAL2_BAUD, SERIAL_8N1, SERIAL2_RX, SERIAL2_TX);

  analogReadResolution(12);
  analogSetAttenuation(ADC_11db);

  sampleTimer = timerBegin(1000000);
  timerAttachInterrupt(sampleTimer, &onSampleTimer);
  timerAlarm(sampleTimer, TIMER_PERIOD_US, true, 0);

  Serial.println("=========================================");
  Serial.println("  FHR/BPM Monitor - XIAO ESP32-S3");
  Serial.println("  3-Task Architecture (MQTT + HTTP)");
  Serial.println("  Warm-up 1 detik...");
  Serial.println("=========================================");

  // WiFi
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

  // MQTT
  secureClientFHR.setInsecure();
  mqttClientFHR.setServer(MQTT_HOST, MQTT_PORT);
  mqttClientFHR.setBufferSize(256);

  // Queue
  mqttQueueFHR = xQueueCreate(3,  sizeof(PaketMQTT_FHR));  // kecil = data terbaru
  httpQueueFHR = xQueueCreate(10, sizeof(PaketHTTP_FHR));   // lebih besar untuk DB

  // Task MQTT — prioritas 2
  xTaskCreatePinnedToCore(taskMQTT_FHR, "taskMQTT_FHR", 8192, NULL, 2, NULL, 0);

  // Task HTTP — prioritas 1
  xTaskCreatePinnedToCore(taskHTTP_FHR, "taskHTTP_FHR", 8192, NULL, 1, NULL, 0);
}

// ════════════════════════════════════════════════════════════
//   PROSES SINYAL ADC
// ════════════════════════════════════════════════════════════
void processSample() {
  int   raw = analogRead(ADC_PIN);
  float x0  = (raw / ADC_RESOLUTION) * REF_VOLTAGE;

  float hy0 = HB0*x0+HB1*hx1+HB2*hx2-HA1*hy1-HA2*hy2;
  hx2=hx1; hx1=x0; hy2=hy1; hy1=hy0;

  float ay0 = LB0_A*hy0+LB1_A*ax1+LB2_A*ax2-LA1_A*ay1-LA2_A*ay2;
  ax2=ax1; ax1=hy0; ay2=ay1; ay1=ay0;

  float rect = fabsf(ay0);

  float ey0 = LB0_E*rect+LB1_E*ex1+LB2_E*ex2-LA1_E*ey1-LA2_E*ey2;
  ex2=ex1; ex1=rect; ey2=ey1; ey1=ey0;

  float ey0b = LB0_E*ey0+LB1_E*ex3+LB2_E*ex4-LA1_E*ey3-LA2_E*ey4;
  ex4=ex3; ex3=ey0; ey4=ey3; ey3=ey0b;

  envelope_signal = ey0b * 10000.0f;

  if (!baseline_init) { baseline_ema = envelope_signal; baseline_init = true; }
  else baseline_ema += BASELINE_ALPHA * (envelope_signal - baseline_ema);

  if (warmup_count < WARMUP_SAMPLES) { warmup_count++; return; }

  float thr   = baseline_ema * SPIKE_RATIO;
  unsigned long nowMs = millis();

  if (!aboveThresh) {
    if (envelope_signal > thr && (nowMs - lastPeakTime) >= REFRACTORY_MS) {
      aboveThresh = true; peakHold = envelope_signal;
    }
  } else {
    if (envelope_signal > peakHold) peakHold = envelope_signal;
    if (envelope_signal <= thr) {
      float interval = (nowMs - lastPeakTime) / 1000.0f;
      if (interval >= PEAK_MIN_INTERVAL && interval <= PEAK_MAX_INTERVAL) {
        peakIntervals[peakIdx] = interval;
        peakIdx = (peakIdx + 1) % 5;
        if (validPeakCount < 5) validPeakCount++;
        peaksThisSecond++;
      }
      lastPeakTime = nowMs;
      aboveThresh  = false;
      peakHold     = 0;
    }
  }
}

// ── Validasi Konsistensi ─────────────────────────────────────
bool isConsistent(float *arr, int n) {
  if (n < 3) return false;
  float mean = 0;
  for (int i=0; i<n; i++) mean += arr[i];
  mean /= n;
  if (mean <= 0) return false;
  float var = 0;
  for (int i=0; i<n; i++) { float d=arr[i]-mean; var+=d*d; }
  return (sqrtf(var/n) / mean) <= MAX_COV;
}

// ── Update BPM (tiap 500ms) ──────────────────────────────────
void updateBPM() {
  unsigned long nowMs = millis();
  if (warmup_count < WARMUP_SAMPLES) return;

  static bool headerPrinted = false;
  if (!headerPrinted) {
    Serial.println("Siap. Tempelkan sensor ke pasien.");
    Serial.println("-----------------------------------------");
    headerPrinted = true;
  }

  // Reset jika timeout
  if (lastPeakTime > 0 && (nowMs - lastPeakTime) > NO_SIGNAL_TIMEOUT) {
    validPeakCount = 0; aboveThresh = false; peakHold = 0; stableBPM = 0;
    for (int i=0; i<5; i++) peakIntervals[i] = 0;
  }

  // Hitung BPM
  float currentBPM = 0;
  if (validPeakCount >= MIN_VALID_PEAKS) {
    float valid[5]; int cnt = 0;
    for (int i=0; i<5; i++)
      if (peakIntervals[i] > 0) valid[cnt++] = peakIntervals[i];
    if (cnt >= 3 && isConsistent(valid, cnt)) {
      std::sort(valid, valid + cnt);
      float median = valid[cnt / 2];
      if (median > 0) {
        float bpm = 60.0f / median;
        if (bpm >= BPM_MIN && bpm <= BPM_MAX) {
          currentBPM = bpm; stableBPM = bpm; stableBPMTime = nowMs;
        }
      }
    }
  }

  float displayBPM = 0;
  if (currentBPM > 0) displayBPM = currentBPM;
  else if (stableBPM > 0 && (nowMs - stableBPMTime) < 5000) displayBPM = stableBPM;

  String status  = getDJJStatus(displayBPM);
  String quality = (peaksThisSecond >= 1 && peaksThisSecond <= 3) ? " [OK]"
                 : (peaksThisSecond > 3) ? " [noise?]" : " [lemah]";

  // Serial Monitor
  if (displayBPM > 0)
    Serial.printf("BPM: %.1f bpm | Status: %s%s\n", displayBPM, status.c_str(), quality.c_str());
  else
    Serial.println("BPM: -- (tidak ada sinyal)");

  // Kirim ke Serial1 (ke ESP32 TOCO) + MQTT + HTTP tiap SEND_INTERVAL_MS
  static unsigned long lastSendMs = 0;
  if (nowMs - lastSendMs >= SEND_INTERVAL_MS) {
    lastSendMs = nowMs;

    // Serial1
    if (displayBPM > 0) {
      Serial1.print((int)displayBPM); Serial1.print(",");
      Serial1.print(status); Serial1.print("\n");
    } else {
      Serial1.print("0,NoSignal\n");
    }

    // MQTT + HTTP (non-blocking, masing-masing ke queue sendiri)
    kirimFHR((int)round(displayBPM));
  }

  peaksThisSecond = 0;
}

// ════════════════════════════════════════════════════════════
//   LOOP — Core 1
// ════════════════════════════════════════════════════════════
void loop() {
  if (sampleFlag) { sampleFlag = false; processSample(); }

  static unsigned long lastUpdate = 0;
  if (millis() - lastUpdate >= 500) {
    lastUpdate = millis();
    updateBPM();
  }
}
