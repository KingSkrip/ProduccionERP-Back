<?php
// database/migrations/2026_07_08_000000_add_jerarquia_a_puestos.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('puestos', function (Blueprint $table) {
            $table->boolean('es_gerente')->default(false)->after('descripcion');
            $table->boolean('es_jefe_area')->default(false)->after('es_gerente');
            $table->boolean('es_rh')->default(false)->after('es_jefe_area');
               $table->boolean('es_subordinado')->default(false)->after('es_rh');
        });
    }

    public function down(): void
    {
        Schema::table('puestos', function (Blueprint $table) {
            $table->dropColumn(['es_gerente', 'es_jefe_area', 'es_rh', 'es_subordinado']);
        });
    }
};