async function uploadImage(file) {
  const formData = new FormData();
  formData.append('image', file);

  const res = await fetch('/api/upload-image.php', {
    method: 'POST',
    body: formData
  });

  const data = await res.json();
  if (!data.success) throw new Error(data.message);

  return data.url;
}
