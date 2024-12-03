@extends('layout')

@section('content')
    <div class="container mt-5">
        <h1 class="text-center">PDF Parser</h1>
        <form method="post" action="{{ route('parse.pdf.submit1') }}" enctype="multipart/form-data">
            @csrf
            <div class="mb-3">
                <label for="fileUpload" class="form-label">PDF Parser, method 1</label>
                <input class="form-control" type="file" name="pdf" accept="application/pdf" required>
            </div>
            <button type="submit" class="btn btn-primary">Parse</button>
        </form>
        <form class="mt-5" method="post" action="{{ route('parse.pdf.submit2') }}" enctype="multipart/form-data">
            @csrf
            <div class="mb-3">
                <label for="fileUpload" class="form-label">PDF Parser, method 2</label>
                <input class="form-control" type="file" name="pdf2" accept="application/pdf" required>
            </div>
            <button type="submit" class="btn btn-primary">Parse</button>
        </form>
    </div>
@endsection

