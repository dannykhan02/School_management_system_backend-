// Configuration
$email = 'admin123@gmail.com';  // Change this to your email
$password = 'password123';     // Change this to your password

// Find user
$user = App\Models\User::where('email', $email)->first();

if (!$user) {
    echo "❌ User not found with email: {$email}\n";
    exit;
}

// Verify password
if (!Hash::check($password, $user->password)) {
    echo "❌ Invalid password\n";
    exit;
}

// Create token
$token = $user->createToken('tinker-login-' . now()->format('Y-m-d-H-i-s'))->plainTextToken;

// Display results
echo "\n✅ Login Successful!\n";
echo "==========================================\n";
echo "Token: {$token}\n";
echo "==========================================\n";
echo "User Details:\n";
echo "  ID: {$user->id}\n";
echo "  Name: {$user->full_name}\n";
echo "  Email: {$user->email}\n";
echo "  Phone: {$user->phone}\n";
echo "  Status: {$user->status}\n";

if ($user->role) {
    echo "  Role: {$user->role->name}\n";
}

if ($user->school) {
    echo "  School: {$user->school->name}\n";
    echo "  School ID: {$user->school->id}\n";
}

echo "==========================================\n";
echo "\nUse this token in your API requests:\n";
echo "Authorization: Bearer {$token}\n";