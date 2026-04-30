<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\SendInvoiceEmail;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class InvoiceController extends Controller
{
    private function authorizeInvoiceAccess(Invoice $invoice): void
    {
        $user = auth()->user();

        if ($user->hasRole('super-admin')) {
            return;
        }

        if ((int) $invoice->recipient_user_id === (int) $user->id) {
            return;
        }

        if ($user->hasRole('owner') && (int) $invoice->property?->user_id === (int) $user->id) {
            return;
        }

        if ($user->hasRole('property_manager') && (int) $invoice->property?->manager_id === (int) $user->id) {
            return;
        }

        abort(403, 'Unauthorized');
    }

    public function index(Request $request)
    {
        $user = auth()->user();

        $query = Invoice::query()->with([
            'recipient:id,name,email',
            'tenant:id,name,email',
            'property:id,property_name,user_id,manager_id',
        ])->latest();

        if (! $user->hasRole('super-admin')) {
            $query->where(function ($builder) use ($user) {
                $builder->where('recipient_user_id', $user->id);

                if ($user->hasRole('owner')) {
                    $builder->orWhereHas('property', fn ($property) => $property->where('user_id', $user->id));
                }

                if ($user->hasRole('property_manager')) {
                    $builder->orWhereHas('property', fn ($property) => $property->where('manager_id', $user->id));
                }
            });
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        if ($request->filled('property_id')) {
            $query->where('property_id', (int) $request->input('property_id'));
        }

        return response()->json($query->paginate((int) $request->integer('per_page', 10)));
    }

    public function show(Invoice $invoice)
    {
        $invoice->loadMissing(['recipient:id,name,email', 'tenant:id,name,email', 'property:id,property_name,user_id,manager_id']);
        $this->authorizeInvoiceAccess($invoice);

        return response()->json($invoice);
    }

    public function download(Invoice $invoice)
    {
        $invoice->loadMissing('property');
        $this->authorizeInvoiceAccess($invoice);
        abort_if(! $invoice->pdf_path || ! $invoice->pdf_disk, 404, 'Invoice PDF not found');

        return Storage::disk($invoice->pdf_disk)->download(
            $invoice->pdf_path,
            $invoice->invoice_number.'.pdf',
            ['Content-Type' => 'application/pdf']
        );
    }

    public function resend(Invoice $invoice)
    {
        $invoice->loadMissing('property');
        $this->authorizeInvoiceAccess($invoice);

        SendInvoiceEmail::dispatch($invoice->id);

        return response()->json(['message' => 'Invoice email queued for resend.']);
    }
}
