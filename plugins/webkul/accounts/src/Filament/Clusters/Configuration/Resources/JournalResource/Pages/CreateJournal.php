<?php

namespace Webkul\Account\Filament\Clusters\Configuration\Resources\JournalResource\Pages;

use Illuminate\Support\Facades\Auth;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Webkul\Account\Filament\Clusters\Configuration\Resources\JournalResource;
use Webkul\Account\Enums\CommunicationStandard;
use Webkul\Account\Enums\CommunicationType;
use Webkul\Account\Models\Journal;

class CreateJournal extends CreateRecord
{
    protected static string $resource = JournalResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title(__('accounts::filament/clusters/configurations/resources/journal/pages/create-journal.notification.title'))
            ->body(__('accounts::filament/clusters/configurations/resources/journal/pages/create-journal.notification.body'));
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['sort'] = Journal::max('sort') + 1;

        $data['creator_id'] = Auth::user()->id;

        $data['invoice_reference_type'] = $data['invoice_reference_type'] ?? CommunicationType::INVOICE->value;
        $data['invoice_reference_model'] = $data['invoice_reference_model'] ?? CommunicationStandard::AUREUS->value;

        return $data;
    }
}
