
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Image resizer</title>
    <script>
        document.cookie = "resolution=" + Math.max(screen.width, screen.height)+"; path=/; SameSite=None";
    </script>
</head>
<body>
<img src="images/nature-8727795.jpg" alt="test1" style="width: 100%">
<script>
    window.addEventListener("resize", () => {
        document.cookie = "resolution=" + Math.max(screen.width, screen.height)+"; path=/; SameSite=None";
    });
</script>
</body>
</html>

