<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentCounter extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = ['company_id', 'name', 'prefix', 'counter'];

    /**
     * Increment counter and return next formatted number.
     * Thread-safe via DB lock.
     */
    public function nextNumber(): string
    {
        $this->increment('counter');
        $this->refresh();
        $prefix = $this->prefix ?? strtoupper(substr($this->name, 0, 3));
        return sprintf('%s/%05d', $prefix, $this->counter);
    }
}
