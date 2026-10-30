package com.cuanforwarder.app;

import android.app.Notification;
import android.content.ComponentName;
import android.content.Context;
import android.os.Bundle;
import android.provider.Settings;
import android.service.notification.NotificationListenerService;
import android.service.notification.StatusBarNotification;

import org.json.JSONObject;

import java.security.MessageDigest;
import java.util.List;
import java.util.Locale;
import java.util.Set;

/**
 * Menerima setiap notifikasi yang muncul di HP. Notifikasi dari aplikasi yang dipilih
 * dan berisi nominal (Rp/IDR) langsung dimasukkan antrian lalu dikirim ke webhook.
 * Saat saklar utama mati, notifikasi hanya dicatat di daftar terbaru dan tidak diteruskan.
 * Layanan ini diikat oleh sistem Android, jadi tetap berjalan walau layar mati.
 */
public class NotifListener extends NotificationListenerService {
    public static volatile boolean connected = false;

    public static boolean isEnabled(Context c) {
        String flat = Settings.Secure.getString(c.getContentResolver(), "enabled_notification_listeners");
        return flat != null && flat.contains(c.getPackageName());
    }

    /** Minta sistem mengikat ulang listener (dipakai bila terputus). */
    public static void rebind(Context c) {
        try {
            requestRebind(new ComponentName(c, NotifListener.class));
        } catch (Exception ignored) {}
    }

    @Override
    public void onListenerConnected() {
        connected = true;
        Prefs.log(this, "ok", "Izin baca notifikasi aktif, listener tersambung");
        Util.startService(this);
    }

    @Override
    public void onListenerDisconnected() {
        connected = false;
        Prefs.log(this, "warn", "Listener terputus, minta sambung ulang");
        rebind(this);
    }

    @Override
    public void onNotificationPosted(StatusBarNotification sbn) {
        try {
            handle(sbn);
        } catch (Exception e) {
            Prefs.log(this, "err", "Gagal membaca notifikasi: " + e.getMessage());
        }
    }

    private void handle(StatusBarNotification sbn) throws Exception {
        String pkg = sbn.getPackageName();
        if (pkg.equals(getPackageName())) return;
        Notification n = sbn.getNotification();
        if (n == null || (n.flags & Notification.FLAG_GROUP_SUMMARY) != 0) return;

        Bundle ex = n.extras;
        String title = str(ex.getCharSequence(Notification.EXTRA_TITLE));
        String text = str(ex.getCharSequence(Notification.EXTRA_TEXT));
        String big = str(ex.getCharSequence(Notification.EXTRA_BIG_TEXT));
        String sub = str(ex.getCharSequence(Notification.EXTRA_SUB_TEXT));
        CharSequence[] lines = ex.getCharSequenceArray(Notification.EXTRA_TEXT_LINES);
        StringBuilder all = new StringBuilder();
        for (String s : new String[]{title, text, big, sub}) if (!s.isEmpty()) all.append(s).append(" | ");
        if (lines != null) for (CharSequence l : lines) all.append(l).append(" | ");
        String joined = all.toString().trim();
        if (joined.isEmpty()) return;

        String app = Util.appLabel(this, pkg);
        Prefs.addRecent(this, pkg, app, title.isEmpty() ? joined : title + " — " + (big.isEmpty() ? text : big));

        Set<String> apps = Prefs.apps(this);
        if (!apps.contains(pkg) || !Prefs.enabled(this)) return;

        long amount = Forwarder.parseAmount(big.isEmpty() ? text : big);
        if (amount <= 0) amount = Forwarder.parseAmount(joined);
        if (amount <= 0) {
            Prefs.log(this, "info", app + ": notifikasi tanpa nominal, dilewati");
            return;
        }
        long min = Prefs.minAmount(this);
        if (min > 0 && amount < min) {
            Prefs.log(this, "info", app + ": " + Util.rp(amount) + " di bawah minimum " + Util.rp(min) + ", dilewati");
            return;
        }
        List<String> kws = Prefs.keywordList(this);
        if (!kws.isEmpty()) {
            String low = joined.toLowerCase(Locale.ROOT);
            boolean hit = false;
            for (String k : kws) if (low.contains(k)) { hit = true; break; }
            if (!hit) {
                Prefs.log(this, "info", app + ": " + Util.rp(amount) + " tanpa kata kunci, dilewati");
                return;
            }
        }

        // ID unik per notifikasi (sama dengan yang dicek server untuk duplikat)
        long bucket = sbn.getPostTime() / (10 * 60 * 1000L);
        String uid = sha1(sbn.getKey() + "|" + joined + "|" + bucket);
        if (!Prefs.markSeen(this, uid)) return;

        JSONObject item = new JSONObject();
        item.put("uid", uid);
        item.put("package", pkg);
        item.put("app", app);
        item.put("title", title);
        item.put("text", text);
        item.put("big_text", big);
        item.put("sub_text", sub);
        item.put("amount", amount);
        item.put("posted_at", sbn.getPostTime() / 1000);
        item.put("queued_at", System.currentTimeMillis());
        Prefs.log(this, "in", app + ": " + Util.rp(amount) + " masuk antrian");
        Prefs.bump(this, Prefs.ST_IN, 1);
        Forwarder.enqueueAndFlush(this, item);
        Util.startService(this);
    }

    private static String str(CharSequence cs) {
        return cs == null ? "" : cs.toString().trim();
    }

    private static String sha1(String s) throws Exception {
        MessageDigest md = MessageDigest.getInstance("SHA-1");
        byte[] d = md.digest(s.getBytes("UTF-8"));
        StringBuilder sb = new StringBuilder();
        for (byte b : d) sb.append(String.format("%02x", b));
        return sb.toString();
    }
}
