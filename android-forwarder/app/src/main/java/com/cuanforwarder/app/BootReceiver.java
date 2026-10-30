package com.cuanforwarder.app;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

/** HP dinyalakan ulang / aplikasi diperbarui → jalankan lagi layanan forwarder. */
public class BootReceiver extends BroadcastReceiver {
    @Override
    public void onReceive(Context context, Intent intent) {
        String a = String.valueOf(intent.getAction());
        String why = Intent.ACTION_MY_PACKAGE_REPLACED.equals(a) ? "update aplikasi" : a.contains("BOOT") || a.contains("POWERON") ? "HP menyala" : "dijalankan sistem";
        Prefs.log(context, "info", "Aktif kembali setelah " + why);
        Util.startService(context);
        Util.scheduleWatchdog(context, 15 * 60_000L);
        NotifListener.rebind(context);
    }
}
