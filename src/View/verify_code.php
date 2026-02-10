<div class="card">
    <div class="card-header text-center">
        <h3>Enter Verification Code</h3>
    </div>
    <div class="card-body">
        <?php if (isset($error)) { ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error); ?></div>
        <?php } ?>
        <form method="post">
            <input type="hidden" name="step" value="verify_code">
            <div class="mb-3">
                <label for="verification_code" class="form-label">Verification Code</label>
                <input type="text" class="form-control" id="verification_code" name="verification_code" placeholder="Enter the code sent to your email" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">Submit Code</button>
        </form>
    </div>
</div>