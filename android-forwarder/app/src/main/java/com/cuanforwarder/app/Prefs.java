package com.cuanforwarder.app;

import android.content.Context;
import android.content.SharedPreferences;

import org.json.JSONArray;
import org.json.JSONException;
import org.json.JSONObject;

import java.text.SimpleDateFormat;
import java.util.ArrayList;
import java.util.Date;
import java.util.HashSet;
import java.util.List;
import java.util.Locale;
import java.util.Set;

/** Pengaturan, antrian kirim, statistik harian, dan log lokal (SharedPreferences). */
public final class Prefs {
    private static final String NAME = "fwd";
    private static final int MAX_LOG = 150;
    private static final int MAX_RECENT = 30;
    private static final int MAX_SEEN = 400;

    private Prefs() {}

    private static SharedPreferences sp(Context c) {
        return c.getApplicationContext().getSharedPreferences(NAME, Context.MODE_PRIVATE);
    }

    // ── Pengaturan ──────────────────────────────────────────────────────────
    /** Saklar utama: false = notifikasi tidak diteruskan, antrian ditahan. */
    public static boolean enabled(Context c) { return sp(c).getBoolean("enabled", true); }
    public static void setEnabled(Context c, boolean on) { sp(c).edit().putBoolean("enabled", on).apply(); }

    /** URL penuh tujuan notifikasi (POST JSON). Kosong = belum diatur. */
    public static String webhookUrl(Context c) { return sp(c).getString("webhook_url", "").trim(); }
    /** URL penuh heartbeat (opsional). Kosong = heartbeat tidak dikirim. */
    public static String heartbeatUrl(Context c) { return sp(c).getString("heartbeat_url", "").trim(); }
    public static String token(Context c) { return sp(c).getString("token", "").trim(); }
    public static void saveServer(Context c, String webhook, String heartbeat, String token) {
        sp(c).edit().putString("webhook_url", webhook.trim()).putString("heartbeat_url", heartbeat.trim())
                .putString("token", token.trim()).apply();
    }
    public static boolean configured(Context c) { return !webhookUrl(c).isEmpty(); }

    public static Set<String> apps(Context c) {
        return new HashSet<>(sp(c).getStringSet("apps", new HashSet<>()));
    }
    public static void setApps(Context c, Set<String> apps) {
        sp(c).edit().putStringSet("apps", new HashSet<>(apps)).apply();
    }
    public static int heartbeatMinutes(Context c) { return sp(c).getInt("hb_min", 5); }
    public static void setHeartbeatMinutes(Context c, int m) { sp(c).edit().putInt("hb_min", m).apply(); }

    /** Nominal minimum yang diteruskan (0 = semua). */
    public static long minAmount(Context c) { return sp(c).getLong("min_amount", 0); }
    /** Kata kunci wajib, dipisah koma (kosong = semua notifikasi bernominal). */
    public static String keywords(Context c) { return sp(c).getString("keywords", "").trim(); }
    public static void saveFilter(Context c, long min, String keywords) {
        sp(c).edit().putLong("min_amount", Math.max(0, min)).putString("keywords", keywords.trim()).apply();
    }
    public static List<String> keywordList(Context c) {
        List<String> out = new ArrayList<>();
        for (String k : keywords(c).split(",")) {
            k = k.trim().toLowerCase(Locale.ROOT);
            if (!k.isEmpty()) out.add(k);
        }
        return out;
    }

    public static void putLong(Context c, String k, long v) { sp(c).edit().putLong(k, v).apply(); }
    public static long getLong(Context c, String k) { return sp(c).getLong(k, 0); }
    public static void putString(Context c, String k, String v) { sp(c).edit().putString(k, v).apply(); }
    public static String getString(Context c, String k) { return sp(c).getString(k, ""); }

    // ── Statistik harian (reset otomatis tiap ganti hari) ───────────────────
    public static final String ST_IN = "in", ST_OK = "ok", ST_FAIL = "fail", ST_SUM = "sum";

    private static String today() { return new SimpleDateFormat("yyyyMMdd", Locale.ROOT).format(new Date()); }

    public static synchronized long stat(Context c, String key) {
        if (!today().equals(sp(c).getString("st_day", ""))) return 0;
        return sp(c).getLong("st_" + key, 0);
    }
    public static synchronized void bump(Context c, String key, long delta) {
        SharedPreferences s = sp(c);
        SharedPreferences.Editor e = s.edit();
        String d = today();
        if (!d.equals(s.getString("st_day", ""))) {
            e.putString("st_day", d);
            for (String k : new String[]{ST_IN, ST_OK, ST_FAIL, ST_SUM}) e.putLong("st_" + k, 0);
            e.apply();
        }
        sp(c).edit().putLong("st_" + key, sp(c).getLong("st_" + key, 0) + delta).apply();
    }

    // ── Antrian kirim ───────────────────────────────────────────────────────
    public static synchronized JSONArray queue(Context c) {
        try { return new JSONArray(sp(c).getString("queue", "[]")); } catch (JSONException e) { return new JSONArray(); }
    }
    public static synchronized void saveQueue(Context c, JSONArray q) {
        sp(c).edit().putString("queue", q.toString()).commit();
    }
    public static synchronized void enqueue(Context c, JSONObject item) {
        JSONArray q = queue(c);
        q.put(item);
        // Batasi 300 item agar tidak membengkak
        while (q.length() > 300) q.remove(0);
        saveQueue(c, q);
    }

    // ── Dedup notifikasi ────────────────────────────────────────────────────
    public static synchronized boolean markSeen(Context c, String uid) {
        try {
            JSONArray seen = new JSONArray(sp(c).getString("seen", "[]"));
            for (int i = 0; i < seen.length(); i++) if (uid.equals(seen.optString(i))) return false;
            seen.put(uid);
            while (seen.length() > MAX_SEEN) seen.remove(0);
            sp(c).edit().putString("seen", seen.toString()).apply();
            return true;
        } catch (JSONException e) {
            return true;
        }
    }

    // ── Log aktivitas ───────────────────────────────────────────────────────
    /** type: ok · in · info · warn · err · drop */
    public static synchronized void log(Context c, String type, String msg) {
        try {
            JSONArray a = new JSONArray(sp(c).getString("log", "[]"));
            JSONObject o = new JSONObject();
            o.put("t", System.currentTimeMillis());
            o.put("type", type);
            o.put("msg", msg);
            a.put(o);
            while (a.length() > MAX_LOG) a.remove(0);
            sp(c).edit().putString("log", a.toString()).apply();
        } catch (JSONException ignored) {}
    }
    /** Seperti log(), tapi tidak mengulang pesan yang sama dengan entri terakhir. */
    public static synchronized void logOnce(Context c, String type, String msg) {
        JSONArray a = logs(c);
        JSONObject last = a.length() > 0 ? a.optJSONObject(a.length() - 1) : null;
        if (last != null && msg.equals(last.optString("msg"))) return;
        log(c, type, msg);
    }
    public static synchronized JSONArray logs(Context c) {
        try { return new JSONArray(sp(c).getString("log", "[]")); } catch (JSONException e) { return new JSONArray(); }
    }
    public static synchronized void clearLogs(Context c) { sp(c).edit().putString("log", "[]").apply(); }

    // ── Notifikasi terbaru semua aplikasi (untuk memilih aplikasi) ──────────
    public static synchronized void addRecent(Context c, String pkg, String app, String text) {
        try {
            JSONArray a = new JSONArray(sp(c).getString("recent", "[]"));
            JSONObject o = new JSONObject();
            o.put("t", System.currentTimeMillis());
            o.put("pkg", pkg);
            o.put("app", app);
            o.put("text", text.length() > 140 ? text.substring(0, 140) + "…" : text);
            a.put(o);
            while (a.length() > MAX_RECENT) a.remove(0);
            sp(c).edit().putString("recent", a.toString()).apply();
        } catch (JSONException ignored) {}
    }
    public static synchronized JSONArray recent(Context c) {
        try { return new JSONArray(sp(c).getString("recent", "[]")); } catch (JSONException e) { return new JSONArray(); }
    }
}
