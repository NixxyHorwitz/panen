package com.cuanforwarder.app;

import android.animation.ValueAnimator;
import android.content.Context;
import android.content.res.ColorStateList;
import android.graphics.Canvas;
import android.graphics.ColorFilter;
import android.graphics.Paint;
import android.graphics.PixelFormat;
import android.graphics.RectF;
import android.graphics.Typeface;
import android.graphics.drawable.Drawable;
import android.text.InputType;
import android.text.TextUtils;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.view.animation.DecelerateInterpolator;
import android.widget.EditText;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.TextView;

/**
 * Komponen UI bergaya neobrutalism: garis hitam tebal, bayangan keras tanpa blur,
 * warna datar cerah. Semua dibuat di kode supaya aplikasi tetap ringan tanpa AndroidX.
 */
final class Ui {
    static final int BG = 0xFFFFF4E0, INK = 0xFF111111, CARD = 0xFFFFFFFF, MUTED = 0xFF5F5A50, FAINT = 0xFF9A9286,
            YELLOW = 0xFFFFD23F, GREEN = 0xFF4ADE80, RED = 0xFFFF6B6B, BLUE = 0xFF7AA7FF, PURPLE = 0xFFB9A2FF,
            ORANGE = 0xFFFFA94D, GREY = 0xFFE8E1D2, SOFT = 0xFFFFE8B8;

    static final Typeface BLACK = Typeface.create("sans-serif-black", Typeface.NORMAL);
    static final Typeface BOLD = Typeface.create("sans-serif", Typeface.BOLD);
    static final Typeface MED = Typeface.create("sans-serif-medium", Typeface.NORMAL);
    static final Typeface REG = Typeface.create("sans-serif", Typeface.NORMAL);
    static final Typeface MONO = Typeface.create("monospace", Typeface.NORMAL);

    final Context c;
    private final float density;

    Ui(Context c) {
        this.c = c;
        this.density = c.getResources().getDisplayMetrics().density;
    }

    int dp(float v) { return Math.round(v * density); }

    // ── Latar kotak: isi + garis + bayangan keras ───────────────────────────
    static final class NeoDrawable extends Drawable {
        private final Paint p = new Paint(Paint.ANTI_ALIAS_FLAG);
        private final RectF r = new RectF();
        int fill;
        final float radius, border, shadow;
        private boolean pressed;

        NeoDrawable(int fill, float radius, float border, float shadow) {
            this.fill = fill;
            this.radius = radius;
            this.border = border;
            this.shadow = shadow;
        }

        @Override
        public void draw(Canvas cv) {
            float l = getBounds().left, t = getBounds().top, rt = getBounds().right - shadow, b = getBounds().bottom - shadow;
            if (shadow > 0 && !pressed) {
                p.setStyle(Paint.Style.FILL);
                p.setColor(INK);
                r.set(l + shadow, t + shadow, rt + shadow, b + shadow);
                cv.drawRoundRect(r, radius, radius, p);
            }
            p.setStyle(Paint.Style.FILL);
            p.setColor(fill);
            r.set(l, t, rt, b);
            cv.drawRoundRect(r, radius, radius, p);
            if (border > 0) {
                p.setStyle(Paint.Style.STROKE);
                p.setStrokeWidth(border);
                p.setColor(INK);
                r.inset(border / 2f, border / 2f);
                cv.drawRoundRect(r, Math.max(0, radius - border / 2f), Math.max(0, radius - border / 2f), p);
            }
        }

        @Override public boolean isStateful() { return true; }

        @Override
        protected boolean onStateChange(int[] state) {
            boolean now = false;
            for (int s : state) if (s == android.R.attr.state_pressed) now = true;
            if (now == pressed) return false;
            pressed = now;
            invalidateSelf();
            return true;
        }

        @Override public void setAlpha(int a) {}
        @Override public void setColorFilter(ColorFilter cf) {}
        @Override public int getOpacity() { return PixelFormat.TRANSLUCENT; }
    }

    /** Wadah ber-latar neo. Saat ditekan, kotak "turun" ke posisi bayangannya. */
    static class Box extends LinearLayout {
        final NeoDrawable bg;

        Box(Context c, int fill, float radius, float border, float shadow) {
            super(c);
            bg = new NeoDrawable(fill, radius, border, shadow);
            setBackground(bg);
        }

        void pad(int l, int t, int r, int b) {
            setPadding(l, t, r + Math.round(bg.shadow), b + Math.round(bg.shadow));
        }

        void fill(int color) {
            bg.fill = color;
            bg.invalidateSelf();
        }

        @Override
        protected void drawableStateChanged() {
            super.drawableStateChanged();
            float d = isPressed() && isClickable() ? bg.shadow : 0;
            setTranslationX(d);
            setTranslationY(d);
        }
    }

    // ── Saklar on/off ───────────────────────────────────────────────────────
    interface OnToggle { void toggled(boolean on); }

    static final class Switch extends View {
        private final Paint p = new Paint(Paint.ANTI_ALIAS_FLAG);
        private final RectF r = new RectF();
        private final float sh, bw;
        private boolean on;
        private float pos;
        private OnToggle listener;

        Switch(Context c, boolean on, float density) {
            super(c);
            this.on = on;
            this.pos = on ? 1 : 0;
            this.sh = 3 * density;
            this.bw = 2.5f * density;
            setClickable(true);
            setOnClickListener(v -> set(!this.on, true));
            setContentDescription("Saklar");
        }

        void onToggle(OnToggle l) { listener = l; }

        void set(boolean v, boolean notify) {
            if (v == on) return;
            on = v;
            ValueAnimator a = ValueAnimator.ofFloat(pos, on ? 1 : 0);
            a.setDuration(220);
            a.setInterpolator(new DecelerateInterpolator());
            a.addUpdateListener(an -> { pos = (float) an.getAnimatedValue(); invalidate(); });
            a.start();
            if (notify && listener != null) listener.toggled(on);
        }

        @Override
        protected void onDraw(Canvas cv) {
            float w = getWidth() - sh, h = getHeight() - sh, rad = h / 2f;
            p.setStyle(Paint.Style.FILL);
            p.setColor(INK);
            r.set(sh, sh, w + sh, h + sh);
            cv.drawRoundRect(r, rad, rad, p);
            p.setColor(blend(GREY, GREEN, pos));
            r.set(0, 0, w, h);
            cv.drawRoundRect(r, rad, rad, p);
            p.setStyle(Paint.Style.STROKE);
            p.setStrokeWidth(bw);
            p.setColor(INK);
            r.inset(bw / 2f, bw / 2f);
            cv.drawRoundRect(r, rad, rad, p);
            float knob = h / 2f - bw * 2.2f;
            float cx = rad + (w - 2 * rad) * pos;
            p.setStyle(Paint.Style.FILL);
            p.setColor(CARD);
            cv.drawCircle(cx, h / 2f, knob, p);
            p.setStyle(Paint.Style.STROKE);
            p.setColor(INK);
            cv.drawCircle(cx, h / 2f, knob, p);
        }

        private static int blend(int a, int b, float t) {
            int ar = (a >> 16) & 255, ag = (a >> 8) & 255, ab = a & 255;
            int br = (b >> 16) & 255, bg = (b >> 8) & 255, bb = b & 255;
            return 0xFF000000 | (Math.round(ar + (br - ar) * t) << 16) | (Math.round(ag + (bg - ag) * t) << 8) | Math.round(ab + (bb - ab) * t);
        }
    }

    Switch toggle(boolean on, OnToggle l) {
        Switch s = new Switch(c, on, density);
        s.onToggle(l);
        s.setLayoutParams(new LinearLayout.LayoutParams(dp(58), dp(34)));
        return s;
    }

    // ── Pabrik komponen ─────────────────────────────────────────────────────
    TextView text(CharSequence s, float sp, int color, Typeface tf) {
        TextView t = new TextView(c);
        t.setText(s);
        t.setTextSize(sp);
        t.setTextColor(color);
        t.setTypeface(tf);
        t.setIncludeFontPadding(false);
        t.setLineSpacing(0, 1.15f);
        return t;
    }

    TextView label(String s) {
        TextView t = text(s.toUpperCase(java.util.Locale.ROOT), 11, MUTED, BLACK);
        t.setLetterSpacing(0.08f);
        return t;
    }

    TextView oneLine(TextView t) {
        t.setSingleLine(true);
        t.setEllipsize(TextUtils.TruncateAt.END);
        return t;
    }

    ImageView icon(int res, int sizeDp, int color) {
        ImageView iv = new ImageView(c);
        iv.setImageResource(res);
        iv.setImageTintList(ColorStateList.valueOf(color));
        iv.setLayoutParams(new LinearLayout.LayoutParams(dp(sizeDp), dp(sizeDp)));
        return iv;
    }

    /** Ikon dalam kotak berwarna bergaris (badge). */
    Box tile(int res, int fill, int boxDp, int iconDp, int iconColor) {
        Box b = new Box(c, fill, dp(10), dp(2), 0);
        b.setGravity(Gravity.CENTER);
        b.addView(icon(res, iconDp, iconColor));
        b.setLayoutParams(new LinearLayout.LayoutParams(dp(boxDp), dp(boxDp)));
        return b;
    }

    Box tile(Drawable d, int boxDp, int iconDp) {
        Box b = new Box(c, CARD, dp(10), dp(2), 0);
        b.setGravity(Gravity.CENTER);
        ImageView iv = new ImageView(c);
        iv.setImageDrawable(d);
        iv.setLayoutParams(new LinearLayout.LayoutParams(dp(iconDp), dp(iconDp)));
        b.addView(iv);
        b.setLayoutParams(new LinearLayout.LayoutParams(dp(boxDp), dp(boxDp)));
        return b;
    }

    Box card(int fill) {
        Box b = new Box(c, fill, dp(14), dp(2.5f), dp(4));
        b.setOrientation(LinearLayout.VERTICAL);
        b.pad(dp(14), dp(13), dp(14), dp(14));
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(-1, -2);
        lp.bottomMargin = dp(12);
        b.setLayoutParams(lp);
        return b;
    }

    /** Judul kartu: ikon kecil + teks + elemen kanan (opsional). */
    LinearLayout cardHead(int res, String title, View right) {
        LinearLayout row = hrow();
        row.addView(icon(res, 18, INK));
        TextView t = text(title, 15, INK, BLACK);
        t.setPadding(dp(8), 0, 0, 0);
        row.addView(t, new LinearLayout.LayoutParams(0, -2, 1));
        if (right != null) row.addView(right);
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(-1, -2);
        lp.bottomMargin = dp(10);
        row.setLayoutParams(lp);
        return row;
    }

    Box button(String label, int iconRes, int fill, View.OnClickListener l) {
        Box b = new Box(c, fill, dp(12), dp(2.5f), dp(3));
        b.setOrientation(LinearLayout.HORIZONTAL);
        b.setGravity(Gravity.CENTER);
        b.pad(dp(12), 0, dp(12), 0);
        b.setClickable(true);
        b.setFocusable(true);
        b.setOnClickListener(l);
        b.setContentDescription(label);
        if (iconRes != 0) {
            ImageView iv = icon(iconRes, 18, INK);
            b.addView(iv);
        }
        TextView t = oneLine(text(label, 14, INK, BLACK));
        if (iconRes != 0) t.setPadding(dp(7), 0, 0, 0);
        b.addView(t);
        b.setLayoutParams(new LinearLayout.LayoutParams(-1, dp(48)));
        return b;
    }

    Box chip(String label, boolean selected, View.OnClickListener l) {
        Box b = new Box(c, selected ? YELLOW : CARD, dp(99), dp(2), selected ? dp(2) : 0);
        b.setGravity(Gravity.CENTER);
        b.pad(dp(12), dp(6), dp(12), dp(6));
        b.setClickable(l != null);
        if (l != null) b.setOnClickListener(l);
        b.addView(oneLine(text(label, 12.5f, INK, selected ? BLACK : BOLD)));
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(-2, -2);
        lp.rightMargin = dp(6);
        b.setLayoutParams(lp);
        return b;
    }

    /** Pil status kecil, mis. AKTIF / DIJEDA. */
    Box pill(String label, int fill) {
        Box b = new Box(c, fill, dp(99), dp(2), 0);
        b.setGravity(Gravity.CENTER);
        b.pad(dp(9), dp(4), dp(9), dp(4));
        TextView t = text(label, 10.5f, INK, BLACK);
        t.setLetterSpacing(0.06f);
        b.addView(t);
        return b;
    }

    EditText input(String hint, String value, int type) {
        EditText e = new EditText(c);
        e.setHint(hint);
        e.setText(value);
        e.setSingleLine(true);
        e.setInputType(type == 0 ? InputType.TYPE_CLASS_TEXT : type);
        e.setTextColor(INK);
        e.setHintTextColor(FAINT);
        e.setTextSize(14);
        e.setTypeface(MED);
        e.setPadding(dp(12), dp(11), dp(12), dp(11));
        e.setBackground(new NeoDrawable(CARD, dp(10), dp(2), 0));
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(-1, -2);
        lp.topMargin = dp(5);
        lp.bottomMargin = dp(10);
        e.setLayoutParams(lp);
        return e;
    }

    LinearLayout hrow() {
        LinearLayout r = new LinearLayout(c);
        r.setOrientation(LinearLayout.HORIZONTAL);
        r.setGravity(Gravity.CENTER_VERTICAL);
        return r;
    }

    LinearLayout vcol() {
        LinearLayout r = new LinearLayout(c);
        r.setOrientation(LinearLayout.VERTICAL);
        return r;
    }

    /** Baris daftar: badge kiri, judul + keterangan, elemen kanan. */
    LinearLayout listRow(View lead, String title, String sub, View trail, View.OnClickListener l) {
        LinearLayout row = hrow();
        row.setPadding(0, dp(8), 0, dp(8));
        if (lead != null) row.addView(lead);
        LinearLayout col = vcol();
        col.setPadding(lead != null ? dp(11) : 0, 0, dp(8), 0);
        col.addView(oneLine(text(title, 14, INK, BOLD)));
        if (sub != null && !sub.isEmpty()) {
            TextView s = text(sub, 12, MUTED, REG);
            s.setMaxLines(2);
            s.setEllipsize(TextUtils.TruncateAt.END);
            s.setPadding(0, dp(3), 0, 0);
            col.addView(s);
        }
        row.addView(col, new LinearLayout.LayoutParams(0, -2, 1));
        if (trail != null) row.addView(trail);
        if (l != null) {
            row.setClickable(true);
            row.setOnClickListener(l);
            row.setBackground(c.getDrawable(android.R.drawable.list_selector_background));
        }
        return row;
    }

    View divider() {
        View v = new View(c);
        v.setBackgroundColor(GREY);
        v.setLayoutParams(new LinearLayout.LayoutParams(-1, dp(1.5f)));
        return v;
    }

    View gap(int dpW, int dpH) {
        View v = new View(c);
        v.setLayoutParams(new LinearLayout.LayoutParams(dp(dpW), dp(dpH)));
        return v;
    }

    static LinearLayout.LayoutParams weight(float w) {
        return new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, w);
    }
}
