<?php session_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Setup Municipality | RHU-MIS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f9fafb;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .setup-box {
            max-width: 500px;
            background: #fff;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>

<div class="setup-box">
    <h4 class="text-center mb-4">Start New Municipality Setup</h4>

    <form action="save_municipality.php" method="POST">
        <div class="mb-3">
            <label class="form-label">Municipality</label>
            <select name="municipality_name" class="form-select" required>
                <option value="">Select Municipality</option>
                <option value="Oroquieta City">Oroquieta City</option>
                <option value="Ozamiz City">Ozamiz City</option>
                <option value="Tangub City">Tangub City</option>
                <option value="Aloran">Aloran</option>
                <option value="Baliangao">Baliangao</option>
                <option value="Bonifacio">Bonifacio</option>
                <option value="Calamba">Calamba</option>
                <option value="Clarin">Clarin</option>
                <option value="Concepcion">Concepcion</option>
                <option value="Don Victoriano">Don Victoriano</option>
                <option value="Jimenez">Jimenez</option>
                <option value="Lopez Jaena">Lopez Jaena</option>
                <option value="Panaon">Panaon</option>
                <option value="Plaridel">Plaridel</option>
                <option value="Sapang Dalaga">Sapang Dalaga</option>
                <option value="Sinacaban">Sinacaban</option>
                <option value="Tudela">Tudela</option>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label">Province</label>
            <input type="text" name="province" class="form-control" value="Misamis Occidental" readonly>
        </div>

        <button type="submit" class="btn btn-success w-100">Save & Continue</button>
    </form>
</div>

</body>
</html>
