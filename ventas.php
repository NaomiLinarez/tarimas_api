package com.example.tarmiastovar;

import android.content.Intent;
import android.os.Bundle;
import android.view.View;
import android.widget.Button;
import android.widget.TextView;
import android.widget.Toast;
import androidx.appcompat.app.AppCompatActivity;
import com.google.android.material.appbar.MaterialToolbar;
import com.google.android.material.textfield.TextInputEditText;
import org.json.JSONArray;
import org.json.JSONObject;
import java.util.Locale;

public class ActivityCobro extends AppCompatActivity {

    private double totalCobro      = 0;
    private int    cantNuevas      = 0;
    private int    cantEstandar    = 0;
    private int    cantEncachetada = 0;
    private int    cantBarrote     = 0;
    private int    cantTacon       = 0;
    private int    cantRep         = 0;
    private int    cantEsp         = 0;

    private double precioNueva       = 280.0;
    private double precioEstandar    = 280.0;
    private double precioEncachetada = 300.0;
    private double precioBarrote     = 290.0;
    private double precioTacon       = 290.0;
    private double precioRep         = 120.0;
    private double precioEsp         = 350.0;

    private String usuarioId = "";

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_cobro);

        totalCobro       = getIntent().getDoubleExtra("total",             0);
        cantNuevas       = getIntent().getIntExtra("cant_nuevas",          0);
        cantEstandar     = getIntent().getIntExtra("cant_estandar",        0);
        cantEncachetada  = getIntent().getIntExtra("cant_encachetada",     0);
        cantBarrote      = getIntent().getIntExtra("cant_barrote",         0);
        cantTacon        = getIntent().getIntExtra("cant_tacon",           0);
        cantRep          = getIntent().getIntExtra("cant_rep",             0);
        cantEsp          = getIntent().getIntExtra("cant_esp",             0);
        precioNueva       = getIntent().getDoubleExtra("precio_nueva",      280.0);
        precioEstandar    = getIntent().getDoubleExtra("precio_estandar",   280.0);
        precioEncachetada = getIntent().getDoubleExtra("precio_encachetada",300.0);
        precioBarrote     = getIntent().getDoubleExtra("precio_barrote",    290.0);
        precioTacon       = getIntent().getDoubleExtra("precio_tacon",      290.0);
        precioRep         = getIntent().getDoubleExtra("precio_rep",        120.0);
        precioEsp         = getIntent().getDoubleExtra("precio_esp",        350.0);
        usuarioId         = getIntent().getStringExtra("usuario_id");
        if (usuarioId == null) usuarioId = SessionManager.getId(this);
        if (usuarioId == null) usuarioId = "";

        MaterialToolbar toolbar = (MaterialToolbar) ((com.google.android.material.appbar.AppBarLayout)
                findViewById(R.id.appbar_cobro)).getChildAt(0);
        toolbar.setNavigationOnClickListener(v -> onBackPressed());

        setResumenItem(R.id.tv_resumen_nuevas,       cantNuevas);
        setResumenItem(R.id.tv_resumen_estandar,     cantEstandar);
        setResumenItem(R.id.tv_resumen_encachetada,  cantEncachetada);
        setResumenItem(R.id.tv_resumen_barrote,      cantBarrote);
        setResumenItem(R.id.tv_resumen_tacon,        cantTacon);
        setResumenItem(R.id.tv_resumen_rep,          cantRep);
        setResumenItem(R.id.tv_resumen_esp,          cantEsp);

        ocultarSiCero(R.id.row_resumen_nuevas,      cantNuevas);
        ocultarSiCero(R.id.row_resumen_estandar,    cantEstandar);
        ocultarSiCero(R.id.row_resumen_encachetada, cantEncachetada);
        ocultarSiCero(R.id.row_resumen_barrote,     cantBarrote);
        ocultarSiCero(R.id.row_resumen_tacon,       cantTacon);
        ocultarSiCero(R.id.row_resumen_rep,         cantRep);
        ocultarSiCero(R.id.row_resumen_esp,         cantEsp);

        TextView tvTotal = findViewById(R.id.tv_total_cobro);
        if (tvTotal != null)
            tvTotal.setText(String.format(Locale.getDefault(), "$%.2f", totalCobro));

        // Aviso de transferencia — sin emojis
        TextView tvAviso = findViewById(R.id.tv_aviso_pago);
        if (tvAviso != null) {
            tvAviso.setVisibility(View.VISIBLE);
            tvAviso.setText(
                    "Tu pedido se registra como transferencia bancaria.\n" +
                            "Se generara un ticket. Llevalo al punto de venta para recoger " +
                            "tu pedido y realizar el pago indicado.\n\n" +
                            "IMPORTANTE: Sin ticket no se entrega el pedido."
            );
        }

        // Ocultar botones de metodo de pago (solo transferencia)
        hideView(R.id.btn_pago_efectivo);
        hideView(R.id.btn_pago_transferencia);
        hideView(R.id.btn_pago_credito);
        hideView(R.id.layout_monto_recibido);

        // Campos condicionales
        View layoutMedida  = findViewById(R.id.layout_medida_especial);
        View layoutTipoRep = findViewById(R.id.layout_tipo_reparacion);
        if (layoutMedida  != null) layoutMedida.setVisibility(cantEsp > 0 ? View.VISIBLE : View.GONE);
        if (layoutTipoRep != null) layoutTipoRep.setVisibility(cantRep > 0 ? View.VISIBLE : View.GONE);

        // Confirmar pedido
        Button btnConfirmar = findViewById(R.id.btn_confirmar_cobro);
        if (btnConfirmar != null) {
            btnConfirmar.setOnClickListener(v -> {

                // Nombre — obligatorio
                TextInputEditText etNombre = findViewById(R.id.et_nombre_cliente_cobro);
                String nombreCliente = (etNombre != null && etNombre.getText() != null)
                        ? etNombre.getText().toString().trim() : "";
                if (nombreCliente.isEmpty()) {
                    if (etNombre != null) etNombre.setError("El nombre es obligatorio");
                    Toast.makeText(this, "Ingresa el nombre del cliente", Toast.LENGTH_SHORT).show();
                    return;
                }

                // Medida especial — obligatorio si cantEsp > 0
                String medidaEspecial = "";
                if (cantEsp > 0) {
                    TextInputEditText etMedida = findViewById(R.id.et_medida_especial);
                    medidaEspecial = (etMedida != null && etMedida.getText() != null)
                            ? etMedida.getText().toString().trim() : "";
                    if (medidaEspecial.isEmpty()) {
                        if (etMedida != null) etMedida.setError("Especifica la medida requerida");
                        Toast.makeText(this, "Especifica la medida de la tarima especial", Toast.LENGTH_SHORT).show();
                        return;
                    }
                }

                // Tipo de reparacion — obligatorio si cantRep > 0
                String tipoReparacion = "";
                if (cantRep > 0) {
                    TextInputEditText etTipoRep = findViewById(R.id.et_tipo_reparacion);
                    tipoReparacion = (etTipoRep != null && etTipoRep.getText() != null)
                            ? etTipoRep.getText().toString().trim() : "";
                    if (tipoReparacion.isEmpty()) {
                        if (etTipoRep != null) etTipoRep.setError("Indica el tipo de tarima a reparar");
                        Toast.makeText(this, "Indica el tipo de tarima para reparacion", Toast.LENGTH_SHORT).show();
                        return;
                    }
                }

                btnConfirmar.setEnabled(false);
                btnConfirmar.setText("Registrando...");

                String finalNombre  = nombreCliente;
                String finalMedida  = medidaEspecial;
                String finalTipoRep = tipoReparacion;

                try {
                    JSONObject payload = new JSONObject();
                    payload.put("nombre_cliente",  nombreCliente);
                    payload.put("total",           totalCobro);
                    payload.put("metodo_pago",     "transferencia");
                    payload.put("monto_recibido",  totalCobro);
                    payload.put("estado_pago",     "pendiente");
                    if (!medidaEspecial.isEmpty()) payload.put("medida_especial", medidaEspecial);
                    if (!tipoReparacion.isEmpty()) payload.put("tipo_reparacion", tipoReparacion);
                    if (!usuarioId.isEmpty())      payload.put("registrada_por",  usuarioId);

                    JSONArray detalle = new JSONArray();
                    agregarItem(detalle, "tarima_nueva", cantNuevas,      precioNueva,       "");
                    agregarItem(detalle, "estandar",     cantEstandar,    precioEstandar,    "");
                    agregarItem(detalle, "encachetada",  cantEncachetada, precioEncachetada, "");
                    agregarItem(detalle, "barrote",      cantBarrote,     precioBarrote,     "");
                    agregarItem(detalle, "tacon",        cantTacon,       precioTacon,       "");
                    agregarItem(detalle, "reparacion",   cantRep,         precioRep,         tipoReparacion);
                    agregarItem(detalle, "especial",     cantEsp,         precioEsp,         medidaEspecial);
                    payload.put("detalle", detalle);

                    ApiClient.post("/ventas.php", payload, new ApiClient.Callback() {
                        @Override
                        public void onSuccess(JSONObject result) {
                            String ventaId = result.optString("venta_id", "");
                            // Enviar notificación y luego ir al ticket dentro del callback
                            enviarNotificacionAdmin(finalNombre, ventaId, finalMedida, finalTipoRep,
                                    () -> irAlTicket(finalNombre, ventaId, finalMedida, finalTipoRep));
                        }
                        @Override
                        public void onError(String error) {
                            runOnUiThread(() -> {
                                btnConfirmar.setEnabled(true);
                                btnConfirmar.setText("Confirmar pedido");
                                new androidx.appcompat.app.AlertDialog.Builder(ActivityCobro.this)
                                        .setTitle("Error al registrar pedido")
                                        .setMessage(error)
                                        .setPositiveButton("OK", null)
                                        .show();
                            });
                        }
                    });

                } catch (Exception e) {
                    btnConfirmar.setEnabled(true);
                    btnConfirmar.setText("Confirmar pedido");
                    Toast.makeText(this, "Error: " + e.getMessage(), Toast.LENGTH_SHORT).show();
                }
            });
        }

        Button btnCancelar = findViewById(R.id.btn_cancelar_cobro);
        if (btnCancelar != null) {
            btnCancelar.setOnClickListener(v ->
                    new androidx.appcompat.app.AlertDialog.Builder(this)
                            .setTitle("Cancelar pedido")
                            .setMessage("Estas seguro de que deseas cancelar?")
                            .setPositiveButton("Si, cancelar", (d, w) -> {
                                Intent intent = new Intent(this, ActivityPresentacion.class);
                                intent.setFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP);
                                startActivity(intent);
                                finish();
                            })
                            .setNegativeButton("No", null)
                            .show()
            );
        }
    }

    // Helpers

    private void setResumenItem(int viewId, int cantidad) {
        TextView tv = findViewById(viewId);
        if (tv != null) tv.setText(cantidad + " pzs");
    }

    private void ocultarSiCero(int rowId, int cantidad) {
        View row = findViewById(rowId);
        if (row != null) row.setVisibility(cantidad > 0 ? View.VISIBLE : View.GONE);
    }

    private void hideView(int id) {
        View v = findViewById(id);
        if (v != null) v.setVisibility(View.GONE);
    }

    private void agregarItem(JSONArray arr, String tipo, int cantidad,
                             double precio, String extra) {
        if (cantidad <= 0) return;
        try {
            JSONObject item = new JSONObject();
            item.put("tipo",        tipo);
            item.put("cantidad",    cantidad);
            item.put("precio_unit", precio);
            if (!extra.isEmpty()) {
                if (tipo.equals("especial"))   item.put("medida",      extra);
                if (tipo.equals("reparacion")) item.put("tipo_tarima", extra);
            }
            arr.put(item);
        } catch (Exception ignored) {}
    }

    private void enviarNotificacionAdmin(String nombreCliente, String ventaId,
                                         String medidaEsp, String tipoRep,
                                         Runnable onFinish) {
        try {
            JSONObject payload = new JSONObject();
            payload.put("titulo", "Nuevo pedido recibido");
            String cliente = (nombreCliente != null && !nombreCliente.isEmpty())
                    ? nombreCliente : "Cliente";

            StringBuilder msg = new StringBuilder();
            msg.append("Cliente: ").append(cliente)
                    .append(" | Total: $").append(String.format(Locale.getDefault(), "%.2f", totalCobro))
                    .append(" | TRANSFERENCIA");
            if (cantNuevas > 0)       msg.append("\n- Tarima nueva: ").append(cantNuevas);
            if (cantEstandar > 0)     msg.append("\n- Estandar: ").append(cantEstandar);
            if (cantEncachetada > 0)  msg.append("\n- Encachetada: ").append(cantEncachetada);
            if (cantBarrote > 0)      msg.append("\n- Barrote: ").append(cantBarrote);
            if (cantTacon > 0)        msg.append("\n- Tacon: ").append(cantTacon);
            if (cantRep > 0) {
                msg.append("\n- Reparacion: ").append(cantRep);
                if (!tipoRep.isEmpty()) msg.append(" (").append(tipoRep).append(")");
            }
            if (cantEsp > 0) {
                msg.append("\n- Medida especial: ").append(cantEsp);
                if (!medidaEsp.isEmpty()) msg.append(" - ").append(medidaEsp);
            }

            payload.put("cuerpo",   msg.toString());
            payload.put("venta_id", ventaId);
            payload.put("tipo",     "nuevo_pedido");
            payload.put("destino",  "admin");
            if (!medidaEsp.isEmpty()) payload.put("medida_especial", medidaEsp);
            if (!tipoRep.isEmpty())   payload.put("tipo_reparacion", tipoRep);

            ApiClient.post("/notificar_admin.php", payload, new ApiClient.Callback() {
                @Override public void onSuccess(JSONObject r) {
                    if (onFinish != null) runOnUiThread(onFinish);
                }
                @Override public void onError(String e) {
                    // Aunque falle el push FCM, igual navegamos al ticket
                    if (onFinish != null) runOnUiThread(onFinish);
                }
            });
        } catch (Exception e) {
            // Si hay excepcion construyendo el payload, igual navegamos al ticket
            if (onFinish != null) runOnUiThread(onFinish);
        }
    }

    private void irAlTicket(String nombreCliente, String ventaId,
                            String medidaEspecial, String tipoReparacion) {
        Intent intent = new Intent(this, ActivityTicket.class);
        intent.putExtra("total",             totalCobro);
        intent.putExtra("cant_nuevas",       cantNuevas);
        intent.putExtra("cant_estandar",     cantEstandar);
        intent.putExtra("cant_encachetada",  cantEncachetada);
        intent.putExtra("cant_barrote",      cantBarrote);
        intent.putExtra("cant_tacon",        cantTacon);
        intent.putExtra("cant_rep",          cantRep);
        intent.putExtra("cant_esp",          cantEsp);
        intent.putExtra("precio_nueva",      precioNueva);
        intent.putExtra("precio_estandar",   precioEstandar);
        intent.putExtra("precio_encachetada",precioEncachetada);
        intent.putExtra("precio_barrote",    precioBarrote);
        intent.putExtra("precio_tacon",      precioTacon);
        intent.putExtra("precio_rep",        precioRep);
        intent.putExtra("precio_esp",        precioEsp);
        intent.putExtra("metodo_pago",       "TRANSFERENCIA");
        intent.putExtra("nombre_cliente",    nombreCliente);
        intent.putExtra("venta_id",          ventaId);
        intent.putExtra("medida_especial",   medidaEspecial);
        intent.putExtra("tipo_reparacion",   tipoReparacion);
        startActivity(intent);
        finish();
    }
}
