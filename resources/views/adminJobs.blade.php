@extends('include.app')
@section('content')
<section class="section">
    <div class="row">
        <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted">Offres</div><h4>{{ $kpis['total_offers'] }}</h4></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted">Publiées</div><h4>{{ $kpis['published_offers'] }}</h4></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted">Candidatures</div><h4>{{ $kpis['total_applications'] }}</h4></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted">Entreprises</div><h4>{{ $kpis['total_companies'] }}</h4></div></div></div>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="page-title w-100">
                <div class="d-flex align-items-center justify-content-between">
                    <h4 class="mb-0 fw-normal d-flex align-items-center">Jobs entreprises</h4>
                    <a href="{{ route('adminCompanies') }}" class="btn btn-primary">Voir entreprises</a>
                </div>
            </div>
        </div>
        <div class="card-body">
            @if (session('success'))
                <div class="alert alert-success">{{ session('success') }}</div>
            @endif
            @if (session('error'))
                <div class="alert alert-danger">{{ session('error') }}</div>
            @endif

            <form method="GET" action="{{ route('adminJobs') }}" class="row g-2 mb-4">
                <div class="col-md-5">
                    <input type="text" name="keyword" class="form-control" placeholder="Titre, domaine, entreprise..." value="{{ request('keyword') }}">
                </div>
                <div class="col-md-3">
                    <select name="status" class="form-control">
                        <option value="">Tous les statuts</option>
                        @foreach (['draft' => 'Brouillon', 'published' => 'Publiée', 'closed' => 'Clôturée', 'rejected' => 'Rejetée'] as $value => $label)
                            <option value="{{ $value }}" {{ request('status') === $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Filtrer</button></div>
                <div class="col-md-2"><a href="{{ route('adminJobs') }}" class="btn btn-light w-100">Reset</a></div>
            </form>

            <div class="table-responsive">
                <table class="table table-striped w-100">
                    <thead>
                        <tr>
                            <th>Offre</th>
                            <th>Entreprise</th>
                            <th>Type</th>
                            <th>Localisation</th>
                            <th>Candidatures</th>
                            <th>Statut</th>
                            <th>Créée</th>
                            <th style="text-align: right; width: 220px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($jobs as $job)
                            <tr>
                                <td>
                                    <strong>{{ $job->title }}</strong>
                                    @if ($job->domain)
                                        <div class="text-muted small">{{ $job->domain }}</div>
                                    @endif
                                </td>
                                <td>
                                    @if ($job->company)
                                        <span class="badge bg-info mb-1">Entreprise</span>
                                        <div>{{ $job->company->name }}</div>
                                        <div class="text-muted small">{{ $job->company->email }}</div>
                                    @else
                                        <span class="text-muted">Entreprise supprimée</span>
                                    @endif
                                </td>
                                <td>{{ strtoupper($job->contract_type) }}</td>
                                <td>{{ $job->location_type }} @if ($job->location_city) · {{ $job->location_city }} @endif</td>
                                <td>{{ $job->applications_count }}</td>
                                <td><span class="badge bg-secondary">{{ $job->status }}</span></td>
                                <td>{{ optional($job->created_at)->format('d-m-Y') }}</td>
                                <td class="text-end">
                                    <form method="POST" action="{{ route('adminModerateJobWeb') }}" class="d-flex gap-1 justify-content-end">
                                        @csrf
                                        <input type="hidden" name="job_offer_id" value="{{ $job->id }}">
                                        <select name="status" class="form-control form-control-sm" style="max-width: 120px;">
                                            @foreach (['draft', 'published', 'closed', 'rejected'] as $candidateStatus)
                                                <option value="{{ $candidateStatus }}" {{ $job->status === $candidateStatus ? 'selected' : '' }}>{{ $candidateStatus }}</option>
                                            @endforeach
                                        </select>
                                        <button type="submit" class="btn btn-sm btn-primary">OK</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center text-muted py-4">Aucune offre trouvée.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-3">{{ $jobs->links() }}</div>
        </div>
    </div>
</section>
@endsection
