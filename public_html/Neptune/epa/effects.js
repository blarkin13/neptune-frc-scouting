// Public stats client behavior. No animated background/compositor layers.
(()=>{
  const input=document.querySelector('[data-team-filter]');
  if(!input)return;
  input.addEventListener('input',()=>{
    const q=input.value.trim();
    document.querySelectorAll('tr[data-team]').forEach(row=>{
      row.hidden=!row.dataset.team.includes(q);
    });
  });
})();
