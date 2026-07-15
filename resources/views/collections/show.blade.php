@extends('layouts.app', ['title' => $seo['title'], 'seo' => $seo])

@section('content')
    <livewire:collections.catalog-collection-page
        :collection-public-id="$collection->public_id"
        :interface-locale="$interfaceLocale"
        :wire:key="'catalog-collection-page-'.$collection->public_id"
    />
@endsection
