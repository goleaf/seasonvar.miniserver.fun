@extends('layouts.app', ['title' => $seo['title'] ?? 'Сводка каталога', 'seo' => $seo ?? []])

@section('content')
    <livewire:stats-dashboard />
@endsection
