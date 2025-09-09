@extends('master')

@section('title')
    available
@endsection

@section('content')
    <main role="main" class="main-content">
        <div class="container-fluid">
            <div class="row justify-content-center">
                <div class="col-12">
                    <h2 class="page-title">Available services</h2>
                    <div class="row">
                        <div class="col-md-3 mb-4">
                            <div class="card shadow">
                                <div class="card-header">
                                    <strong class="card-title">Virtual server</strong>
                                </div>
                                <div class="card-body">
                                    <button type="button" class="btn btn-outline-primary">Order</button>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-4">
                            <div class="card shadow">
                                <div class="card-header">
                                    <strong class="card-title">Hi-CPU server</strong>
                                </div>
                                <div class="card-body">
                                    <div class="mb">
                                        <button type="button" class="btn btn-outline-primary">Order</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-4">
                            <div class="card shadow">
                                <div class="card-header">
                                    <strong class="card-title">Dedicated Server</strong>
                                </div>
                                <div class="card-body">
                                    <button type="button" class="btn btn-outline-primary">Order</button>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-4">
                            <div class="card shadow">
                                <div class="card-header">
                                    <strong class="card-title">Domain</strong>
                                </div>
                                <div class="card-body">
                                    <button type="button" class="btn btn-outline-primary">Order</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-3 mb-4">
                            <div class="card shadow">
                                <div class="card-header">
                                    <strong class="card-title">VPN</strong>
                                </div>
                                <div class="card-body">
                                    <button type="button" class="btn btn-outline-primary">Order</button>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-4">
                            <div class="card shadow">
                                <div class="card-header">
                                    <strong class="card-title">License ispmanager</strong>
                                </div>
                                <div class="card-body">
                                    <button type="button" class="btn btn-outline-primary">Order</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
@endsection
