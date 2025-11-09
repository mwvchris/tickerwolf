const onLoad = () => {
  //   Basic Datetimepicker
  const config1 = { enableTime: true };
  const flatpickr1 = document.querySelector("#flatpickr1");
  flatpickr1._datetimepicker = flatpickr(flatpickr1, config1);

  //   Limit Time
  const config2 = { enableTime: true, minTime: "16:00", maxTime: "22:00" };
  const flatpickr2 = document.querySelector("#flatpickr2");
  flatpickr2._datetimepicker = flatpickr(flatpickr2, config2);
};

window.addEventListener("app:mounted", onLoad, { once: true });
