@extends('layouts.app', ['title' => $seo['title'] ?? $title->display_title, 'seo' => $seo ?? []])

@section('content')
    <livewire:catalog-title-detail :catalog-title-id="$title->id" />
@endsection
