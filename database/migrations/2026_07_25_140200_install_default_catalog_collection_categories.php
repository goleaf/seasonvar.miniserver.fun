<?php

declare(strict_types=1);

use App\Services\Collections\CatalogCollectionCategoryDefaults;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new CatalogCollectionCategoryDefaults)->install();
    }

    public function down(): void {}
};
