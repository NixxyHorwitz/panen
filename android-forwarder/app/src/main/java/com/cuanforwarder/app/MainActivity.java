package com.cuanforwarder.app;

import android.Manifest;
import android.app.Activity;
import android.app.Dialog;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.content.pm.ResolveInfo;
import android.graphics.Color;
import android.graphics.drawable.ColorDrawable;
import android.graphics.drawable.Drawable;
import android.os.Build;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.text.Editable;
import android.text.InputType;
import android.text.TextWatcher;
import android.view.Gravity;
import android.view.View;
import android.view.Window;
import android.widget.EditText;
import android.widget.FrameLayout;
import android.widget.HorizontalScrollView;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;
import android.widget.Toast;

import org.json.JSONArray;
import org.json.JSONObject;

import java.text.SimpleDateFormat;
import java.util.ArrayList;
import java.util.Collections;
import java.util.Date;
import java.util.HashSet;
import java.util.List;
import java.util.Locale;
import java.util.Set;

/**
 * Layar utama CuanForwarder: 4 halaman (Beranda, Aktivitas, Aplikasi, Pengaturan)
 * dengan navigasi bawah. UI dibangun di kode (lihat Ui.java) dan diperbarui tiap 2 detik.
 */
public class MainActivity extends Activity {
    private static final int P_HOME = 0, P_LOG = 1, P_APPS = 2, P_SET = 3;
    private static final String[] NAV_LABEL = {"Beranda", "Aktivitas", "Aplikasi", "Pengaturan"};
    private static final int[] NAV_ICON = {R.drawable.ic_home, R.drawable.ic_list, R.drawable.ic_apps, R.drawable.ic_settings};

    private final Handler handler = new Handler(Looper.getMainLooper());
    private final SimpleDateFormat hms = new SimpleDateFormat("HH:mm:ss", Locale.getDefault());
    private final SimpleDateFormat dayFmt = new SimpleDateFormat("d MMM", new Locale("id"));
    private Ui ui;

    private LinearLayout header, nav, page;
    private ScrollView scroll;
    private TextView headSub;
    private FrameLayout headPill;
    private int current = P_HOME;
    private String logFilter = "all";
    private String lastSig = "";
    private boolean showToken = false;

    private final Runnable refresher = new Runnable() {
        @Override
        public void run() {
            refresh(false);
            handler.postDelayed(this, 2000);
        }
    };

    @Override
    protected void onCreate(Bundle b) {
        super.onCreate(b);
        ui = new Ui(this);
        Window w = getWindow();
        w.setStatusBarColor(Ui.BG);
        w.setNavigationBarColor(Ui.CARD);
        w.getDecorView().setSystemUiVisibility(View.SYSTEM_UI_FLAG_LIGHT_STATUS_BAR | View.SYSTEM_UI_FLAG_LIGHT_NAVIGATION_BAR);

        LinearLayout root = ui.vcol();
        root.setBackgroundColor(Ui.BG);

        header = buildHeader();
        root.addView(header);

        scroll = new ScrollView(this);
        scroll.setFillViewport(true);
        scroll.setClipToPadding(false);
        FrameLayout center = new FrameLayout(this);
        page = ui.vcol();
        page.setPadding(ui.dp(16), ui.dp(6), ui.dp(16), ui.dp(24));
        // Halaman sendiri yang memegang fokus, agar kolom isian tidak otomatis memunculkan keyboard
        page.setFocusableInTouchMode(true);
        // Lebar konten dibatasi di layar lebar (tablet / lipat) agar tetap rapi
        FrameLayout.LayoutParams plp = new FrameLayout.LayoutParams(-1, -2, Gravity.CENTER_HORIZONTAL);
        center.addView(page, plp);
        center.addOnLayoutChangeListener((v, l, t, r, bt, ol, ot, or, ob) -> {
            int max = ui.dp(620), wid = r - l;
            int want = wid > max ? max : -1;
            if (page.getLayoutParams().width != want) {
                page.getLayoutParams().width = want;
                page.post(page::requestLayout);
            }
        });
        scroll.addView(center);
        root.addView(scroll, new LinearLayout.LayoutParams(-1, 0, 1));

        nav = buildNav();
        root.addView(nav);
        setContentView(root);

        if (Build.VERSION.SDK_INT >= 35) {
            root.setOnApplyWindowInsetsListener((v, insets) -> {
                header.setPadding(ui.dp(16), ui.dp(12) + insets.getSystemWindowInsetTop(), ui.dp(16), ui.dp(10));
                nav.setPadding(ui.dp(10), ui.dp(8), ui.dp(10), ui.dp(8) + insets.getSystemWindowInsetBottom());
                return insets;
            });
        }

        Util.startService(this);
        Util.scheduleWatchdog(this, 15 * 60_000L);
        show(P_HOME);
    }

    @Override protected void onResume() { super.onResume(); refresh(true); handler.postDelayed(refresher, 2000); }
    @Override protected void onPause() { super.onPause(); handler.removeCallbacks(refresher); }

    @Override
    public void onBackPressed() {
        if (current != P_HOME) show(P_HOME); else super.onBackPressed();
    }

    // ── Kerangka: header & navigasi bawah ───────────────────────────────────
    private LinearLayout buildHeader() {
        LinearLayout h = ui.hrow();
        h.setPadding(ui.dp(16), ui.dp(12), ui.dp(16), ui.dp(10));
        android.widget.ImageView logo = new android.widget.ImageView(this);
        logo.setImageResource(R.drawable.logo);
        h.addView(logo, new LinearLayout.LayoutParams(ui.dp(42), ui.dp(42)));
        LinearLayout col = ui.vcol();
        col.setPadding(ui.dp(10), 0, ui.dp(8), 0);
        col.addView(ui.text("CuanForwarder", 19, Ui.INK, Ui.BLACK));
        headSub = ui.oneLine(ui.text("", 12, Ui.MUTED, Ui.MED));
        headSub.setPadding(0, ui.dp(3), 0, 0);
        col.addView(headSub);
        h.addView(col, Ui.weight(1));
        headPill = new FrameLayout(this);
        h.addView(headPill);
        return h;
    }

    private LinearLayout buildNav() {
        LinearLayout n = ui.hrow();
        n.setBackgroundColor(Ui.CARD);
        n.setPadding(ui.dp(10), ui.dp(8), ui.dp(10), ui.dp(8));
        n.setElevation(0);
        return n;
    }

    private void renderNav() {
        nav.removeAllViews();
        // garis atas tebal khas neobrutalism
        nav.setBackground(new android.graphics.drawable.LayerDrawable(new Drawable[]{
                new ColorDrawable(Ui.INK), insetTop(new ColorDrawable(Ui.CARD), ui.dp(2.5f))}));
        for (int i = 0; i < NAV_LABEL.length; i++) {
            final int idx = i;
            boolean on = i == current;
            LinearLayout item = ui.vcol();
            item.setGravity(Gravity.CENTER_HORIZONTAL);
            item.setClickable(true);
            item.setContentDescription(NAV_LABEL[i]);
            item.setOnClickListener(v -> show(idx));
            Ui.Box ic = new Ui.Box(this, on ? Ui.YELLOW : Ui.CARD, ui.dp(99), on ? ui.dp(2) : 0, 0);
            ic.setGravity(Gravity.CENTER);
            ic.addView(ui.icon(NAV_ICON[i], 21, on ? Ui.INK : Ui.MUTED));
            item.addView(ic, new LinearLayout.LayoutParams(ui.dp(54), ui.dp(30)));
            TextView t = ui.text(NAV_LABEL[i], 11, on ? Ui.INK : Ui.MUTED, on ? Ui.BLACK : Ui.BOLD);
            t.setPadding(0, ui.dp(4), 0, 0);
            item.addView(t);
            nav.addView(item, Ui.weight(1));
        }
    }

    private static Drawable insetTop(Drawable d, int top) {
        return new android.graphics.drawable.InsetDrawable(d, 0, top, 0, 0);
    }

    private void renderHeader() {
        boolean on = Prefs.enabled(this);
        int missing = missingSteps();
        String sub = !on ? "Forwarding dijeda" : missing > 0 ? missing + " langkah lagi agar siap" : "Meneruskan notifikasi pembayaran";
        headSub.setText(sub);
        headPill.removeAllViews();
        headPill.addView(!on ? ui.pill("DIJEDA", Ui.GREY) : missing > 0 ? ui.pill("PERLU ATUR", Ui.ORANGE) : ui.pill("AKTIF", Ui.GREEN));
    }

    private void show(int p) {
        boolean changed = p != current;
        current = p;
        renderNav();
        refresh(true);
        if (changed) scroll.scrollTo(0, 0);
    }

    /** Bangun ulang halaman bila datanya berubah (atau dipaksa). Tidak mengganggu saat mengetik. */
    private void refresh(boolean force) {
        String sig = signature();
        if (!force && sig.equals(lastSig)) return;
        if (!force && getCurrentFocus() instanceof EditText) return;
        lastSig = sig;
        renderHeader();
        int y = scroll.getScrollY();
        page.removeAllViews();
        switch (current) {
            case P_LOG: pageLog(); break;
            case P_APPS: pageApps(); break;
            case P_SET: pageSettings(); break;
            default: pageHome();
        }
        if (!force) scroll.post(() -> scroll.scrollTo(0, y));
        if (!(getCurrentFocus() instanceof EditText)) page.requestFocus();
    }

    private String signature() {
        JSONArray lg = Prefs.logs(this), rc = Prefs.recent(this);
        long lt = lg.length() > 0 ? lg.optJSONObject(lg.length() - 1).optLong("t") : 0;
        long rt = rc.length() > 0 ? rc.optJSONObject(rc.length() - 1).optLong("t") : 0;
        return current + "|" + Prefs.enabled(this) + "|" + NotifListener.isEnabled(this) + NotifListener.connected + Util.batteryExempt(this)
                + postAllowed() + "|" + lt + "|" + rt + "|" + Prefs.queue(this).length() + "|" + Prefs.apps(this) + "|"
                + Prefs.getLong(this, "last_hb") + Prefs.getString(this, "last_hb_result") + "|" + Prefs.stat(this, Prefs.ST_IN)
                + "|" + logFilter + "|" + System.currentTimeMillis() / 60_000;
    }

    // ── Status kesiapan ─────────────────────────────────────────────────────
    private boolean postAllowed() {
        return Build.VERSION.SDK_INT < 33 || checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED;
    }

    private int missingSteps() {
        int m = 0;
        if (!NotifListener.isEnabled(this)) m++;
        if (!Util.batteryExempt(this)) m++;
        if (!Prefs.configured(this)) m++;
        if (Prefs.apps(this).isEmpty()) m++;
        return m;
    }

    // ══ HALAMAN: BERANDA ═════════════════════════════════════════════════════
    private void pageHome() {
        boolean on = Prefs.enabled(this);
        int missing = missingSteps();

        // Kartu saklar utama
        Ui.Box power = ui.card(on ? (missing > 0 ? Ui.ORANGE : Ui.YELLOW) : Ui.GREY);
        LinearLayout row = ui.hrow();
        row.addView(ui.tile(R.drawable.ic_power, on ? Ui.INK : Ui.CARD, 52, 28, on ? Ui.YELLOW : Ui.INK));
        LinearLayout col = ui.vcol();
        col.setPadding(ui.dp(12), 0, ui.dp(8), 0);
        col.addView(ui.label("Forwarding"));
        TextView big = ui.text(on ? "AKTIF" : "DIJEDA", 27, Ui.INK, Ui.BLACK);
        big.setPadding(0, ui.dp(3), 0, 0);
        col.addView(big);
        row.addView(col, Ui.weight(1));
        row.addView(ui.toggle(on, v -> {
            Prefs.setEnabled(this, v);
            Prefs.log(this, v ? "ok" : "warn", v ? "Forwarding diaktifkan" : "Forwarding dijeda");
            Util.startService(this);
            if (v) Forwarder.flush(this);
            handler.postDelayed(() -> refresh(true), 240);
        }));
        power.addView(row);
        TextView desc = ui.text(on
                ? (missing > 0 ? "Masih ada " + missing + " pengaturan yang belum beres. Notifikasi belum bisa diteruskan dengan andal."
                : "Notifikasi pembayaran dari aplikasi yang dipantau diteruskan otomatis, termasuk saat layar mati.")
                : "Notifikasi tidak diteruskan. Antrian ditahan sampai forwarding dinyalakan lagi.", 12.5f, Ui.INK, Ui.MED);
        desc.setPadding(0, ui.dp(12), 0, 0);
        power.addView(desc);
        if (missing > 0) {
            Ui.Box fix = ui.button("Selesaikan pengaturan", R.drawable.ic_chevron, Ui.CARD, v -> show(P_SET));
            LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(-1, ui.dp(44));
            lp.topMargin = ui.dp(12);
            power.addView(fix, lp);
        }
        page.addView(power);

        // Statistik hari ini
        page.addView(sectionLabel("Hari ini"));
        long[] vals = {Prefs.stat(this, Prefs.ST_OK), Prefs.stat(this, Prefs.ST_SUM), Prefs.stat(this, Prefs.ST_IN), Prefs.queue(this).length()};
        String[] lbl = {"Diteruskan", "Nominal", "Tertangkap", "Antrian"};
        int[] ics = {R.drawable.ic_send, R.drawable.ic_wallet, R.drawable.ic_inbox, R.drawable.ic_clock};
        int[] cols = {Ui.GREEN, Ui.BLUE, Ui.PURPLE, Ui.ORANGE};
        boolean wide = getResources().getConfiguration().screenWidthDp >= 560;
        int per = wide ? 4 : 2;
        for (int i = 0; i < 4; i += per) {
            LinearLayout r = ui.hrow();
            for (int j = i; j < i + per; j++) {
                String v = j == 1 ? compactRp(vals[j]) : String.valueOf(vals[j]);
                LinearLayout.LayoutParams lp = Ui.weight(1);
                if (j < i + per - 1) lp.rightMargin = ui.dp(10);
                r.addView(statTile(ics[j], cols[j], v, lbl[j]), lp);
            }
            LinearLayout.LayoutParams rl = new LinearLayout.LayoutParams(-1, -2);
            rl.bottomMargin = ui.dp(10);
            page.addView(r, rl);
        }

        // Kesehatan sistem
        Ui.Box health = ui.card(Ui.CARD);
        int ok = 4 - missing;
        health.addView(ui.cardHead(R.drawable.ic_shield, "Kesehatan sistem", ui.pill(ok + "/4 SIAP", missing == 0 ? Ui.GREEN : Ui.SOFT)));
        healthRow(health, NotifListener.isEnabled(this), "Akses baca notifikasi",
                NotifListener.isEnabled(this) ? (NotifListener.connected ? "Tersambung" : "Menyambung ulang...") : "Wajib, tekan untuk mengizinkan",
                v -> Util.openNotificationAccess(this));
        healthRow(health, Util.batteryExempt(this), "Bebas optimasi baterai",
                Util.batteryExempt(this) ? "Tetap jalan saat layar mati" : "Disarankan agar tidak dimatikan sistem",
                v -> Util.requestBatteryExempt(this));
        healthRow(health, Prefs.configured(this), "Webhook server",
                Prefs.configured(this) ? host(Prefs.webhookUrl(this)) : "Isi URL tujuan di Pengaturan", v -> show(P_SET));
        int n = Prefs.apps(this).size();
        healthRow(health, n > 0, "Aplikasi dipantau", n > 0 ? n + " aplikasi" : "Pilih aplikasi pembayaran", v -> show(P_APPS));
        page.addView(health);

        // Koneksi
        Ui.Box conn = ui.card(Ui.CARD);
        conn.addView(ui.cardHead(R.drawable.ic_server, "Koneksi", null));
        long lastSent = Prefs.getLong(this, "last_sent"), lastHb = Prefs.getLong(this, "last_hb");
        conn.addView(kv("Terakhir terkirim", lastSent > 0 ? ago(lastSent) : "Belum pernah"));
        String hbRes = Prefs.getString(this, "last_hb_result");
        conn.addView(kv("Heartbeat", lastHb > 0 ? ago(lastHb) + " · " + hbRes : (hbRes.isEmpty() ? "Belum pernah" : hbRes)));
        conn.addView(kv("Interval heartbeat", Prefs.heartbeatMinutes(this) + " menit"));
        Ui.Box test = ui.button("Tes koneksi", R.drawable.ic_send, Ui.CARD, v -> testConnection());
        LinearLayout.LayoutParams tl = new LinearLayout.LayoutParams(-1, ui.dp(46));
        tl.topMargin = ui.dp(10);
        conn.addView(test, tl);
        page.addView(conn);

        // Aktivitas terbaru
        Ui.Box act = ui.card(Ui.CARD);
        TextView all = ui.text("Lihat semua", 12.5f, Ui.INK, Ui.BLACK);
        all.setPaintFlags(all.getPaintFlags() | android.graphics.Paint.UNDERLINE_TEXT_FLAG);
        all.setPadding(ui.dp(8), ui.dp(6), 0, ui.dp(6));
        all.setOnClickListener(v -> show(P_LOG));
        act.addView(ui.cardHead(R.drawable.ic_list, "Aktivitas terbaru", all));
        JSONArray logs = Prefs.logs(this);
        if (logs.length() == 0) act.addView(empty("Belum ada aktivitas."));
        for (int i = logs.length() - 1, k = 0; i >= 0 && k < 4; i--, k++) {
            if (k > 0) act.addView(ui.divider());
            act.addView(logRow(logs.optJSONObject(i)));
        }
        page.addView(act);
    }

    private View statTile(int icon, int color, String value, String label) {
        Ui.Box t = new Ui.Box(this, Ui.CARD, ui.dp(14), ui.dp(2.5f), ui.dp(4));
        t.setOrientation(LinearLayout.VERTICAL);
        t.pad(ui.dp(12), ui.dp(12), ui.dp(12), ui.dp(12));
        t.addView(ui.tile(icon, color, 32, 18, Ui.INK));
        TextView v = ui.oneLine(ui.text(value, 22, Ui.INK, Ui.BLACK));
        v.setPadding(0, ui.dp(10), 0, 0);
        t.addView(v);
        TextView l = ui.oneLine(ui.text(label, 12, Ui.MUTED, Ui.BOLD));
        l.setPadding(0, ui.dp(3), 0, 0);
        t.addView(l);
        return t;
    }

    private void healthRow(LinearLayout parent, boolean ok, String title, String sub, View.OnClickListener l) {
        if (parent.getChildCount() > 1) parent.addView(ui.divider());
        Ui.Box badge = ui.tile(ok ? R.drawable.ic_check : R.drawable.ic_close, ok ? Ui.GREEN : Ui.RED, 30, 18, Ui.INK);
        parent.addView(ui.listRow(badge, title, sub, ok ? null : ui.icon(R.drawable.ic_chevron, 20, Ui.INK), ok ? null : l));
    }

    private View kv(String k, String v) {
        LinearLayout r = ui.hrow();
        r.setPadding(0, ui.dp(5), 0, ui.dp(5));
        r.addView(ui.text(k, 13, Ui.MUTED, Ui.MED), Ui.weight(1));
        TextView tv = ui.oneLine(ui.text(v, 13, Ui.INK, Ui.BOLD));
        tv.setGravity(Gravity.END);
        r.addView(tv, Ui.weight(1.3f));
        return r;
    }

    private TextView sectionLabel(String s) {
        TextView t = ui.label(s);
        t.setPadding(ui.dp(2), ui.dp(4), 0, ui.dp(8));
        return t;
    }

    private TextView empty(String s) {
        TextView t = ui.text(s, 13, Ui.MUTED, Ui.MED);
        t.setPadding(0, ui.dp(6), 0, ui.dp(6));
        return t;
    }

    // ══ HALAMAN: AKTIVITAS ═══════════════════════════════════════════════════
    private void pageLog() {
        JSONArray logs = Prefs.logs(this);
        int cOk = 0, cIn = 0, cBad = 0;
        for (int i = 0; i < logs.length(); i++) {
            String t = logs.optJSONObject(i).optString("type");
            if (t.equals("ok")) cOk++; else if (t.equals("in")) cIn++; else if (isProblem(t)) cBad++;
        }
        HorizontalScrollView hs = new HorizontalScrollView(this);
        hs.setHorizontalScrollBarEnabled(false);
        LinearLayout chips = ui.hrow();
        chips.setPadding(0, ui.dp(2), 0, ui.dp(6));
        String[][] f = {{"all", "Semua " + logs.length()}, {"ok", "Berhasil " + cOk}, {"in", "Tertangkap " + cIn}, {"bad", "Masalah " + cBad}};
        for (String[] x : f) chips.addView(ui.chip(x[1], logFilter.equals(x[0]), v -> { logFilter = x[0]; refresh(true); }));
        hs.addView(chips);
        LinearLayout.LayoutParams hl = new LinearLayout.LayoutParams(-1, -2);
        hl.bottomMargin = ui.dp(8);
        page.addView(hs, hl);

        Ui.Box list = ui.card(Ui.CARD);
        int shown = 0;
        String lastDay = "";
        for (int i = logs.length() - 1; i >= 0; i--) {
            JSONObject o = logs.optJSONObject(i);
            String t = o.optString("type");
            boolean match = logFilter.equals("all") || logFilter.equals(t) || (logFilter.equals("bad") && isProblem(t));
            if (!match) continue;
            String day = dayFmt.format(new Date(o.optLong("t")));
            if (!day.equals(lastDay)) {
                TextView d = ui.label(day);
                d.setPadding(0, shown == 0 ? 0 : ui.dp(12), 0, ui.dp(4));
                list.addView(d);
                lastDay = day;
            } else {
                list.addView(ui.divider());
            }
            list.addView(logRow(o));
            shown++;
        }
        if (shown == 0) list.addView(empty("Tidak ada aktivitas untuk filter ini."));
        page.addView(list);

        LinearLayout btns = ui.hrow();
        int q = Prefs.queue(this).length();
        btns.addView(ui.button(q > 0 ? "Kirim antrian (" + q + ")" : "Kirim antrian", R.drawable.ic_refresh, Ui.YELLOW, v -> {
            if (!Prefs.enabled(this)) { toast("Forwarding sedang dijeda"); return; }
            Forwarder.flush(this);
            toast("Mengirim antrian...");
        }), btnLp(true));
        btns.addView(ui.button("Bersihkan", R.drawable.ic_delete, Ui.CARD, v -> confirm("Bersihkan log?",
                "Semua catatan aktivitas di HP ini akan dihapus. Antrian dan statistik tidak terpengaruh.", "Hapus", () -> {
                    Prefs.clearLogs(this);
                    refresh(true);
                })), btnLp(false));
        page.addView(btns);
    }

    private static boolean isProblem(String t) { return t.equals("err") || t.equals("warn") || t.equals("drop"); }

    private View logRow(JSONObject o) {
        String type = o.optString("type");
        int icon, col;
        switch (type) {
            case "ok": icon = R.drawable.ic_check; col = Ui.GREEN; break;
            case "in": icon = R.drawable.ic_inbox; col = Ui.PURPLE; break;
            case "err": icon = R.drawable.ic_close; col = Ui.RED; break;
            case "warn": icon = R.drawable.ic_warning; col = Ui.ORANGE; break;
            case "drop": icon = R.drawable.ic_delete; col = Ui.RED; break;
            default: icon = R.drawable.ic_info; col = Ui.SOFT;
        }
        LinearLayout row = ui.hrow();
        row.setGravity(Gravity.TOP);
        row.setPadding(0, ui.dp(8), 0, ui.dp(8));
        row.addView(ui.tile(icon, col, 28, 16, Ui.INK));
        LinearLayout c = ui.vcol();
        c.setPadding(ui.dp(10), 0, 0, 0);
        TextView m = ui.text(o.optString("msg"), 13, Ui.INK, Ui.MED);
        c.addView(m);
        TextView t = ui.text(hms.format(new Date(o.optLong("t"))), 11, Ui.FAINT, Ui.MONO);
        t.setPadding(0, ui.dp(3), 0, 0);
        c.addView(t);
        row.addView(c, Ui.weight(1));
        return row;
    }

    // ══ HALAMAN: APLIKASI ════════════════════════════════════════════════════
    private void pageApps() {
        Set<String> apps = Prefs.apps(this);
        PackageManager pm = getPackageManager();

        Ui.Box mon = ui.card(Ui.CARD);
        mon.addView(ui.cardHead(R.drawable.ic_apps, "Aplikasi dipantau", ui.pill(String.valueOf(apps.size()), apps.isEmpty() ? Ui.SOFT : Ui.GREEN)));
        if (apps.isEmpty()) {
            mon.addView(empty("Belum ada. Tambahkan aplikasi pembayaran yang notifikasinya ingin diteruskan, misalnya aplikasi merchant QRIS, e-wallet bisnis, atau mobile banking."));
        }
        List<String> sorted = new ArrayList<>(apps);
        Collections.sort(sorted, (a, b) -> Util.appLabel(this, a).compareToIgnoreCase(Util.appLabel(this, b)));
        for (String pkg : sorted) {
            if (mon.getChildCount() > 1) mon.addView(ui.divider());
            Ui.Box rm = ui.tile(R.drawable.ic_close, Ui.CARD, 32, 16, Ui.INK);
            rm.setClickable(true);
            rm.setContentDescription("Berhenti memantau");
            rm.setOnClickListener(v -> confirm("Berhenti memantau?", Util.appLabel(this, pkg) + " tidak akan diteruskan lagi.", "Hapus", () -> {
                Set<String> s = Prefs.apps(this);
                s.remove(pkg);
                Prefs.setApps(this, s);
                Prefs.log(this, "info", "Berhenti memantau " + Util.appLabel(this, pkg));
                refresh(true);
            }));
            mon.addView(ui.listRow(appTile(pm, pkg), Util.appLabel(this, pkg), pkg, rm, null));
        }
        Ui.Box add = ui.button("Tambah aplikasi", R.drawable.ic_add, Ui.YELLOW, v -> pickApps());
        LinearLayout.LayoutParams al = new LinearLayout.LayoutParams(-1, ui.dp(46));
        al.topMargin = ui.dp(10);
        mon.addView(add, al);
        page.addView(mon);

        // Filter
        Ui.Box flt = ui.card(Ui.CARD);
        flt.addView(ui.cardHead(R.drawable.ic_tune, "Filter", null));
        flt.addView(ui.label("Nominal minimum"));
        long min = Prefs.minAmount(this);
        EditText minEt = ui.input("0 = semua nominal", min > 0 ? String.valueOf(min) : "", InputType.TYPE_CLASS_NUMBER);
        flt.addView(minEt);
        flt.addView(ui.label("Kata kunci wajib"));
        EditText kwEt = ui.input("mis. diterima, masuk, berhasil", Prefs.keywords(this), 0);
        flt.addView(kwEt);
        TextView hint = ui.text("Pisahkan dengan koma. Kosongkan agar semua notifikasi bernominal diteruskan.", 12, Ui.MUTED, Ui.REG);
        hint.setPadding(0, 0, 0, ui.dp(10));
        flt.addView(hint);
        flt.addView(ui.button("Simpan filter", R.drawable.ic_check, Ui.CARD, v -> {
            long m;
            try { m = Long.parseLong(minEt.getText().toString().trim()); } catch (NumberFormatException e) { m = 0; }
            Prefs.saveFilter(this, m, kwEt.getText().toString());
            clearFocus();
            toast("Filter disimpan");
            refresh(true);
        }), new LinearLayout.LayoutParams(-1, ui.dp(46)));
        page.addView(flt);

        // Notifikasi terbaru
        Ui.Box rec = ui.card(Ui.CARD);
        rec.addView(ui.cardHead(R.drawable.ic_bell, "Notifikasi terbaru di HP", null));
        TextView tip = ui.text("Notifikasi dari semua aplikasi tertangkap di sini. Tekan Pantau untuk meneruskan aplikasinya.", 12, Ui.MUTED, Ui.REG);
        tip.setPadding(0, 0, 0, ui.dp(4));
        rec.addView(tip);
        JSONArray r = Prefs.recent(this);
        if (r.length() == 0) rec.addView(empty(NotifListener.isEnabled(this) ? "Belum ada notifikasi tertangkap." : "Izinkan akses baca notifikasi dulu."));
        for (int i = r.length() - 1, k = 0; i >= 0 && k < 12; i--, k++) {
            JSONObject o = r.optJSONObject(i);
            String pkg = o.optString("pkg");
            boolean watched = apps.contains(pkg);
            rec.addView(ui.divider());
            Ui.Box chip = ui.chip(watched ? "Dipantau" : "Pantau", watched, watched ? null : v -> {
                Set<String> s = Prefs.apps(this);
                s.add(pkg);
                Prefs.setApps(this, s);
                Prefs.log(this, "info", "Mulai memantau " + o.optString("app"));
                refresh(true);
            });
            ((LinearLayout.LayoutParams) chip.getLayoutParams()).rightMargin = 0;
            rec.addView(ui.listRow(appTile(pm, pkg), o.optString("app") + " · " + hms.format(new Date(o.optLong("t"))).substring(0, 5),
                    o.optString("text"), chip, null));
        }
        page.addView(rec);
    }

    private View appTile(PackageManager pm, String pkg) {
        try {
            return ui.tile(pm.getApplicationIcon(pkg), 38, 28);
        } catch (Exception e) {
            return ui.tile(R.drawable.ic_phone, Ui.GREY, 38, 20, Ui.INK);
        }
    }

    /** Dialog pemilih aplikasi dengan pencarian. */
    private void pickApps() {
        PackageManager pm = getPackageManager();
        List<ResolveInfo> infos = pm.queryIntentActivities(new Intent(Intent.ACTION_MAIN).addCategory(Intent.CATEGORY_LAUNCHER), 0);
        Set<String> chosen = Prefs.apps(this);
        List<String[]> list = new ArrayList<>();
        Set<String> seen = new HashSet<>();
        for (ResolveInfo ri : infos) {
            String pkg = ri.activityInfo.packageName;
            if (pkg.equals(getPackageName()) || !seen.add(pkg)) continue;
            list.add(new String[]{ri.loadLabel(pm).toString(), pkg});
        }
        for (String pkg : chosen) if (seen.add(pkg)) list.add(new String[]{Util.appLabel(this, pkg), pkg});
        Collections.sort(list, (a, b) -> {
            boolean ca = chosen.contains(a[1]), cb = chosen.contains(b[1]);
            if (ca != cb) return ca ? -1 : 1;
            return a[0].compareToIgnoreCase(b[0]);
        });
        Set<String> sel = new HashSet<>(chosen);

        Dialog d = neoDialog();
        Ui.Box box = dialogBox();
        box.addView(ui.cardHead(R.drawable.ic_apps, "Pilih aplikasi", null));
        EditText search = ui.input("Cari nama aplikasi", "", 0);
        search.setCompoundDrawablePadding(ui.dp(8));
        box.addView(search);
        ScrollView sv = new ScrollView(this);
        LinearLayout rows = ui.vcol();
        sv.addView(rows);
        int maxH = (int) (getResources().getDisplayMetrics().heightPixels * 0.5f);
        box.addView(sv, new LinearLayout.LayoutParams(-1, maxH));
        TextView count = ui.text("", 12, Ui.MUTED, Ui.BOLD);
        Runnable[] fill = new Runnable[1];
        fill[0] = () -> {
            rows.removeAllViews();
            String q = search.getText().toString().trim().toLowerCase(Locale.ROOT);
            for (String[] a : list) {
                if (!q.isEmpty() && !a[0].toLowerCase(Locale.ROOT).contains(q) && !a[1].contains(q)) continue;
                boolean on = sel.contains(a[1]);
                Ui.Box check = ui.tile(R.drawable.ic_check, on ? Ui.GREEN : Ui.CARD, 26, 16, on ? Ui.INK : Ui.CARD);
                if (rows.getChildCount() > 0) rows.addView(ui.divider());
                rows.addView(ui.listRow(appTile(pm, a[1]), a[0], a[1], check, v -> {
                    if (!sel.remove(a[1])) sel.add(a[1]);
                    fill[0].run();
                }));
            }
            if (rows.getChildCount() == 0) rows.addView(empty("Tidak ditemukan."));
            count.setText(sel.size() + " dipilih");
        };
        fill[0].run();
        search.addTextChangedListener(new TextWatcher() {
            @Override public void beforeTextChanged(CharSequence s, int a, int b, int c) {}
            @Override public void onTextChanged(CharSequence s, int a, int b, int c) {}
            @Override public void afterTextChanged(Editable s) { fill[0].run(); }
        });
        count.setPadding(0, ui.dp(10), 0, ui.dp(8));
        box.addView(count);
        LinearLayout btns = ui.hrow();
        btns.addView(ui.button("Batal", 0, Ui.CARD, v -> d.dismiss()), btnLp(true));
        btns.addView(ui.button("Simpan", R.drawable.ic_check, Ui.YELLOW, v -> {
            Prefs.setApps(this, sel);
            Prefs.log(this, "info", "Aplikasi dipantau: " + (sel.isEmpty() ? "-" : String.join(", ", Util.appLabels(this, sel))));
            d.dismiss();
            refresh(true);
        }), btnLp(false));
        box.addView(btns);
        d.setContentView(box);
        d.show();
    }

    // ══ HALAMAN: PENGATURAN ══════════════════════════════════════════════════
    private void pageSettings() {
        // Server
        Ui.Box srv = ui.card(Ui.CARD);
        srv.addView(ui.cardHead(R.drawable.ic_server, "Server tujuan", null));
        srv.addView(ui.label("URL webhook"));
        EditText hook = ui.input("https://domainkamu.com/callback", Prefs.webhookUrl(this), InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_URI);
        srv.addView(hook);
        srv.addView(ui.label("URL heartbeat (opsional)"));
        EditText hb = ui.input("https://domainkamu.com/heartbeat", Prefs.heartbeatUrl(this), InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_URI);
        srv.addView(hb);
        srv.addView(ui.label("Token"));
        LinearLayout tokRow = ui.hrow();
        EditText tok = ui.input("Token rahasia dari server", Prefs.token(this),
                InputType.TYPE_CLASS_TEXT | (showToken ? InputType.TYPE_TEXT_VARIATION_VISIBLE_PASSWORD : InputType.TYPE_TEXT_VARIATION_PASSWORD));
        tokRow.addView(tok, new LinearLayout.LayoutParams(0, -2, 1));
        Ui.Box eye = ui.tile(R.drawable.ic_eye, showToken ? Ui.YELLOW : Ui.CARD, 44, 20, Ui.INK);
        eye.setClickable(true);
        eye.setContentDescription("Tampilkan token");
        LinearLayout.LayoutParams el = (LinearLayout.LayoutParams) eye.getLayoutParams();
        el.leftMargin = ui.dp(8);
        el.bottomMargin = ui.dp(5);
        eye.setOnClickListener(v -> {
            showToken = !showToken;
            int sel = tok.getSelectionEnd();
            tok.setInputType(InputType.TYPE_CLASS_TEXT | (showToken ? InputType.TYPE_TEXT_VARIATION_VISIBLE_PASSWORD : InputType.TYPE_TEXT_VARIATION_PASSWORD));
            tok.setTypeface(Ui.MED);
            tok.setSelection(Math.max(0, sel));
            eye.fill(showToken ? Ui.YELLOW : Ui.CARD);
        });
        tokRow.addView(eye);
        srv.addView(tokRow);
        TextView help = ui.text("Setiap notifikasi dikirim sebagai POST JSON (nominal, aplikasi, judul, teks) dengan header X-Forwarder-Token.", 12, Ui.MUTED, Ui.REG);
        help.setPadding(0, 0, 0, ui.dp(10));
        srv.addView(help);
        LinearLayout btns = ui.hrow();
        btns.addView(ui.button("Simpan", R.drawable.ic_check, Ui.YELLOW, v -> {
            String u = hook.getText().toString().trim(), h = hb.getText().toString().trim();
            if (!validUrl(u) || (!h.isEmpty() && !validUrl(h))) { toast("URL harus diawali https:// atau http://"); return; }
            Prefs.saveServer(this, u, h, tok.getText().toString());
            Prefs.log(this, "info", "Server disimpan: " + host(u));
            clearFocus();
            toast("Tersimpan");
            Util.startService(this);
            Forwarder.flush(this);
            refresh(true);
        }), btnLp(true));
        btns.addView(ui.button("Tes", R.drawable.ic_send, Ui.CARD, v -> {
            Prefs.saveServer(this, hook.getText().toString(), hb.getText().toString(), tok.getText().toString());
            testConnection();
        }), btnLp(false));
        srv.addView(btns);
        page.addView(srv);

        // Heartbeat
        Ui.Box hbc = ui.card(Ui.CARD);
        hbc.addView(ui.cardHead(R.drawable.ic_pulse, "Interval heartbeat", null));
        TextView ht = ui.text("Laporan status HP (baterai, izin, antrian) ke URL heartbeat.", 12, Ui.MUTED, Ui.REG);
        ht.setPadding(0, 0, 0, ui.dp(10));
        hbc.addView(ht);
        LinearLayout chips = ui.hrow();
        int cur = Prefs.heartbeatMinutes(this);
        for (int m : new int[]{1, 5, 15, 30}) {
            chips.addView(ui.chip(m + " menit", m == cur, v -> { Prefs.setHeartbeatMinutes(this, m); refresh(true); }));
        }
        hbc.addView(chips);
        page.addView(hbc);

        // Izin & baterai
        Ui.Box perm = ui.card(Ui.CARD);
        perm.addView(ui.cardHead(R.drawable.ic_lock, "Izin & baterai", null));
        permRow(perm, R.drawable.ic_bell, "Akses baca notifikasi", NotifListener.isEnabled(this), v -> Util.openNotificationAccess(this));
        if (Build.VERSION.SDK_INT >= 33) {
            permRow(perm, R.drawable.ic_info, "Izin menampilkan notifikasi", postAllowed(),
                    v -> requestPermissions(new String[]{Manifest.permission.POST_NOTIFICATIONS}, 5));
        }
        permRow(perm, R.drawable.ic_battery, "Bebas optimasi baterai", Util.batteryExempt(this), v -> Util.requestBatteryExempt(this));
        permRow(perm, R.drawable.ic_refresh, "Autostart (Xiaomi/HyperOS)", null, v -> Util.openAutostart(this));
        permRow(perm, R.drawable.ic_battery, "Penghemat baterai: Tanpa batasan", null, v -> Util.openMiuiBattery(this));
        permRow(perm, R.drawable.ic_settings, "Info aplikasi", null, v -> Util.openAppDetails(this));
        TextView tip = ui.text("Di HyperOS/MIUI: buka Recent apps, tahan kartu CuanForwarder, lalu kunci agar tidak ditutup otomatis.", 12, Ui.MUTED, Ui.REG);
        tip.setPadding(0, ui.dp(8), 0, 0);
        perm.addView(tip);
        page.addView(perm);

        // Uji pembaca nominal
        Ui.Box tst = ui.card(Ui.CARD);
        tst.addView(ui.cardHead(R.drawable.ic_code, "Uji pembaca nominal", null));
        EditText sample = ui.input("Tempel teks notifikasi di sini", "", 0);
        sample.setSingleLine(false);
        sample.setMinLines(2);
        tst.addView(sample);
        LinearLayout res = ui.hrow();
        Ui.Box resBadge = ui.tile(R.drawable.ic_info, Ui.SOFT, 28, 16, Ui.INK);
        TextView resTx = ui.text("Hasil muncul saat mengetik.", 13, Ui.INK, Ui.BOLD);
        resTx.setPadding(ui.dp(10), 0, 0, 0);
        res.addView(resBadge);
        res.addView(resTx, Ui.weight(1));
        tst.addView(res);
        sample.addTextChangedListener(new TextWatcher() {
            @Override public void beforeTextChanged(CharSequence s, int a, int b, int c) {}
            @Override public void onTextChanged(CharSequence s, int a, int b, int c) {}
            @Override public void afterTextChanged(Editable s) {
                String txt = s.toString();
                long amt = Forwarder.parseAmount(txt);
                String why = verdict(txt, amt);
                boolean pass = why == null;
                resBadge.removeAllViews();
                resBadge.addView(ui.icon(txt.isEmpty() ? R.drawable.ic_info : pass ? R.drawable.ic_check : R.drawable.ic_close, 16, Ui.INK));
                resBadge.fill(txt.isEmpty() ? Ui.SOFT : pass ? Ui.GREEN : Ui.RED);
                resTx.setText(txt.isEmpty() ? "Hasil muncul saat mengetik." : pass ? "Terbaca " + Util.rp(amt) + ", akan diteruskan" : why);
            }
        });
        page.addView(tst);

        // Tentang
        Ui.Box about = ui.card(Ui.CARD);
        about.addView(ui.cardHead(R.drawable.ic_info, "Tentang", null));
        about.addView(kv("Versi", BuildConfig.VERSION_NAME + " (" + BuildConfig.VERSION_CODE + ")"));
        about.addView(kv("Perangkat", Build.MANUFACTURER + " " + Build.MODEL));
        about.addView(kv("Android", Build.VERSION.RELEASE + " (API " + Build.VERSION.SDK_INT + ")"));
        page.addView(about);
    }

    /** null = lolos semua filter, selain itu alasan ditolak. */
    private String verdict(String txt, long amt) {
        if (amt <= 0) return "Tidak ada nominal (Rp/IDR) di teks ini";
        long min = Prefs.minAmount(this);
        if (min > 0 && amt < min) return Util.rp(amt) + " di bawah minimum " + Util.rp(min);
        List<String> kws = Prefs.keywordList(this);
        if (!kws.isEmpty()) {
            String low = txt.toLowerCase(Locale.ROOT);
            for (String k : kws) if (low.contains(k)) return null;
            return Util.rp(amt) + " terbaca, tapi tidak ada kata kunci wajib";
        }
        return null;
    }

    private void permRow(LinearLayout parent, int icon, String title, Boolean ok, View.OnClickListener l) {
        if (parent.getChildCount() > 1) parent.addView(ui.divider());
        View trail;
        if (ok == null) trail = ui.icon(R.drawable.ic_chevron, 20, Ui.INK);
        else trail = ui.pill(ok ? "AKTIF" : "BELUM", ok ? Ui.GREEN : Ui.RED);
        parent.addView(ui.listRow(ui.tile(icon, Ui.SOFT, 32, 18, Ui.INK), title, null, trail, l));
    }

    // ── Aksi bersama ────────────────────────────────────────────────────────
    private void testConnection() {
        clearFocus();
        if (Prefs.heartbeatUrl(this).isEmpty() && Prefs.webhookUrl(this).isEmpty()) { toast("Isi URL server dulu"); return; }
        toast("Menguji koneksi...");
        Forwarder.heartbeat(this, true, (ok, msg) -> {
            toast(ok ? "Server merespons OK" : "Gagal: " + msg);
            refresh(true);
        });
    }

    private Dialog neoDialog() {
        Dialog d = new Dialog(this);
        d.requestWindowFeature(Window.FEATURE_NO_TITLE);
        if (d.getWindow() != null) d.getWindow().setBackgroundDrawable(new ColorDrawable(Color.TRANSPARENT));
        return d;
    }

    private Ui.Box dialogBox() {
        Ui.Box box = new Ui.Box(this, Ui.BG, ui.dp(16), ui.dp(2.5f), ui.dp(5));
        box.setOrientation(LinearLayout.VERTICAL);
        box.pad(ui.dp(16), ui.dp(16), ui.dp(16), ui.dp(16));
        box.setMinimumWidth((int) Math.min(getResources().getDisplayMetrics().widthPixels * 0.88f, ui.dp(440)));
        return box;
    }

    private void confirm(String title, String msg, String ok, Runnable action) {
        Dialog d = neoDialog();
        Ui.Box box = dialogBox();
        box.addView(ui.cardHead(R.drawable.ic_warning, title, null));
        TextView m = ui.text(msg, 13.5f, Ui.INK, Ui.MED);
        m.setPadding(0, 0, 0, ui.dp(14));
        box.addView(m);
        LinearLayout btns = ui.hrow();
        btns.addView(ui.button("Batal", 0, Ui.CARD, v -> d.dismiss()), btnLp(true));
        btns.addView(ui.button(ok, R.drawable.ic_check, Ui.RED, v -> { d.dismiss(); action.run(); }), btnLp(false));
        box.addView(btns);
        d.setContentView(box);
        d.show();
    }

    private LinearLayout.LayoutParams btnLp(boolean first) {
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(0, ui.dp(48), 1);
        if (first) lp.rightMargin = ui.dp(10);
        return lp;
    }

    private void clearFocus() {
        View f = getCurrentFocus();
        if (f != null) {
            f.clearFocus();
            android.view.inputmethod.InputMethodManager imm = getSystemService(android.view.inputmethod.InputMethodManager.class);
            if (imm != null) imm.hideSoftInputFromWindow(f.getWindowToken(), 0);
        }
    }

    private void toast(String s) { Toast.makeText(this, s, Toast.LENGTH_SHORT).show(); }

    private static boolean validUrl(String u) { return u.startsWith("https://") || u.startsWith("http://"); }

    private static String host(String u) {
        try {
            String h = android.net.Uri.parse(u).getHost();
            return h == null || h.isEmpty() ? u : h;
        } catch (Exception e) {
            return u;
        }
    }

    private String ago(long t) {
        long s = (System.currentTimeMillis() - t) / 1000;
        if (s < 60) return "baru saja";
        if (s < 3600) return (s / 60) + " menit lalu";
        if (s < 86400) return (s / 3600) + " jam lalu";
        return dayFmt.format(new Date(t)) + " " + hms.format(new Date(t)).substring(0, 5);
    }

    private static String compactRp(long v) {
        if (v >= 1_000_000_000L) return String.format(Locale.ROOT, "%.1fM", v / 1e9).replace('.', ',');
        if (v >= 1_000_000L) return String.format(Locale.ROOT, "%.1fjt", v / 1e6).replace('.', ',').replace(",0jt", "jt");
        if (v >= 10_000L) return (v / 1000) + "rb";
        return Util.rp(v);
    }
}
