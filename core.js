const configuredApiBase = "./api/api.php";
const AVAILABILITY_DAYS = ["Wednesday", "Thursday", "Friday", "Saturday", "Sunday", "Monday"];
const SCHEDULE_DAYS = ["Wednesday", "Thursday", "Friday", "Saturday", "Sunday", "Monday"];
const APPLICATION_DAY_OPTIONS = [
  "Wednesday - Truck Loading",
  "Thursday - Load in",
  "Friday",
  "Saturday",
  "Sunday",
  "Sunday Load-out",
  "Monday Truck Unpacking"
];
const DEFAULT_MEAL_WINDOWS = {
  breakfast: "7:00 AM - 10:00 AM",
  lunch: "11:30 AM - 2:00 PM",
  dinner: "5:00 PM - 8:00 PM"
};
const MEAL_WINDOW_OPTIONS = [
  "None",
  "6:00 AM - 8:00 AM",
  "7:00 AM - 10:00 AM",
  "8:00 AM - 10:00 AM",
  "11:00 AM - 1:00 PM",
  "11:30 AM - 2:00 PM",
  "12:00 PM - 2:00 PM",
  "4:00 PM - 6:00 PM",
  "5:00 PM - 8:00 PM",
  "6:00 PM - 9:00 PM"
];
const AVAILABILITY_HOURS = [
  "8:00 AM",
  "9:00 AM",
  "10:00 AM",
  "11:00 AM",
  "12:00 PM",
  "1:00 PM",
  "2:00 PM",
  "3:00 PM",
  "4:00 PM",
  "5:00 PM",
  "6:00 PM",
  "7:00 PM",
  "8:00 PM",
  "9:00 PM",
  "10:00 PM",
  "11:00 PM"
];
const SHIFT_TEMPLATES = [
  { name: "Custom shift", title: "", day: "Thursday", time: "9:00 AM - 1:00 PM", hours: 4, capacity: 2, note: "" },
  { name: "Morning coverage", title: "Morning coverage", day: "Thursday", time: "8:00 AM - 12:00 PM", hours: 4, capacity: 3, note: "Morning operations" },
  { name: "Afternoon coverage", title: "Afternoon coverage", day: "Friday", time: "12:00 PM - 4:00 PM", hours: 4, capacity: 3, note: "Afternoon operations" },
  { name: "Evening coverage", title: "Evening coverage", day: "Friday", time: "4:00 PM - 8:00 PM", hours: 4, capacity: 2, note: "Evening support" },
  { name: "Closeout / loadout", title: "Closeout crew", day: "Sunday", time: "3:00 PM - 7:00 PM", hours: 4, capacity: 4, note: "Loadout support" },
  { name: "Info desk", title: "Info desk coverage", day: "Friday", time: "10:00 AM - 2:00 PM", hours: 4, capacity: 2, note: "Guest-facing desk" }
];

let shifts = [], users = [], hotelRooms = [], systemLogs = [], guestFlights = [], pickupStaff = [], incidents = [], alertRules = [], alertDeliveries = [], vendorHallAssignments = [], currentUser = null, selectedShiftIds = new Set(), selectedVendorHallSpot = null, csrfToken = "";

const VENDOR_HALL_SPOTS = ["A", "B", "C", "D"].flatMap(section =>
  Array.from({ length: 12 }, (_, index) => `${section}${index + 1}`)
);
let selectedAvailabilityDay = "Thursday";
let latestRecommendations = [];
let focusedManagedShiftId = null;
let selectedIncidentId = null;
let selectedVolunteerProfileId = null;
let mealWindows = loadMealWindows();
let applicationFormHydratedUserId = null;

const $ = (s) => document.querySelector(s);
const $$ = (s) => Array.from(document.querySelectorAll(s));
const escapeHtml = (value) => String(value ?? "").replaceAll("&", "&amp;").replaceAll("<", "&lt;").replaceAll(">", "&gt;").replaceAll('"', "&quot;").replaceAll("'", "&#039;");
const isManagementUser = (user) => ["admin", "manager"].includes(user?.role) || ["Admin", "Coordinator"].includes(user?.rank);
const isFullAdmin = (user) => user?.role === "admin" || user?.rank === "Admin";
const needsApplication = (user) => !!user && !isManagementUser(user) && user.status !== "approved";

async function init() {
  bindEvents();
  showDiscordAuthMessage();
  buildAvailabilityControls();
  buildAdminAvailabilityControls();
  populateDepartmentSelects();
  populateMealWindowControls();

  try {
    const res = await fetch(`${configuredApiBase}?action=session`);
    if (!res.ok) throw new Error("API Connection Failed");
    const data = await res.json();
    applyState(data);
  } catch(e) {
    console.error("Init fail", e);
    updateAuthView();
  }
}

function applyState(data) {
  csrfToken = data.csrfToken || csrfToken;
  currentUser = data.user || null;
  users = (data.volunteers || []).map(normalizeUser);
  shifts = data.shifts || [];
  hotelRooms = data.hotelRooms || [];
  systemLogs = data.logs || [];
  guestFlights = data.guestFlights || [];
  pickupStaff = data.pickupStaff || [];
  incidents = data.incidents || [];
  alertRules = data.alertRules || [];
  alertDeliveries = data.alertDeliveries || [];
  vendorHallAssignments = data.vendorHallAssignments || [];
  if (currentUser) {
    currentUser = normalizeUser(currentUser);
    const hydrated = users.find(u => String(u.id) === String(currentUser.id));
    if (hydrated) currentUser = { ...currentUser, ...hydrated };
    selectedShiftIds = new Set(currentUser.shiftIds || []);
  }

  updateAuthView();
  if (currentUser) renderAll();
}

function showDiscordAuthMessage() {
  const params = new URLSearchParams(window.location.search);
  const error = params.get("discord_error");
  const ok = params.get("discord_login");
  const application = params.get("discord_application");
  if ($("#loginMessage") && error) $("#loginMessage").textContent = error;
  if ($("#loginMessage") && ok === "ok") $("#loginMessage").textContent = "Discord login complete.";
  if ($("#loginMessage") && application === "needed") $("#loginMessage").textContent = "Discord linked. Finish the volunteer application.";
  if (error || ok || application) {
    window.history.replaceState({}, document.title, window.location.pathname);
  }
}

async function apiRequest(action, body = null) {
  const res = await fetch(`${configuredApiBase}?action=${action}`, {
    method: body ? "POST" : "GET",
    headers: {
      "Content-Type": "application/json",
      "X-CSRF-Token": csrfToken
    },
    body: body ? JSON.stringify(body) : null
  });
  const data = await res.json();
  if (!res.ok) throw new Error(data.error || "Request failed");
  return data;
}

async function apiUpload(action, formData) {
  const res = await fetch(`${configuredApiBase}?action=${encodeURIComponent(action)}`, {
    method: "POST",
    headers: { "X-CSRF-Token": csrfToken },
    body: formData
  });
  const data = await res.json();
  if (!res.ok) throw new Error(data.error || "Upload failed");
  return data;
}

function bindEvents() {
  const on = (s, e, h) => { const el = $(s); if (el) el.addEventListener(e, h); };

  on("#loginForm", "submit", async (e) => {
    e.preventDefault();
    if ($("#loginMessage")) $("#loginMessage").textContent = "Use Discord to log in.";
  });
  on("#createAccountForm", "input", updateSignupFlow);
  on("#createAccountForm", "change", updateSignupFlow);

  on("#createAccountForm", "submit", async (e) => {
    e.preventDefault();
    try {
      const availability = collectAvailability("apply");
      const applicationDays = collectApplicationDays();
      if (!applicationDays.length) {
        throw new Error("Choose at least one day you are available to volunteer.");
      }
      if (!availabilityHasAny(availability)) {
        throw new Error("Choose at least one available time block.");
      }
      updateSignupFlow();
      const data = await apiRequest("save_application", {
        discord: $("#applyDiscord")?.value || "",
        dateOfBirth: $("#applyDob")?.value || "",
        shirtSize: $("#applyShirtSize")?.value || "",
        appliedDepartment: $("#applyDepartment").value,
        datesAvailable: applicationDays,
        requestedHours: $("#applyRequestedHours")?.value || "",
        hotelNeeded: $("#applyHotel").value,
        gender: $("#applyGender")?.value || "",
        allergies: $("#applyAllergies")?.value || "",
        previousExperience: $("#applyPreviousExperience")?.value || "",
        skills: $("#applySkills")?.value || "",
        additionalNotes: $("#applyAdditionalNotes")?.value || "",
        availability,
        wedLoadout: applicationDays.includes("Wednesday - Truck Loading"),
        sunLoadout: applicationDays.includes("Sunday Load-out")
      });
      applicationFormHydratedUserId = null;
      applyState(data);
      if ($("#createMessage")) $("#createMessage").textContent = "Application saved. A coordinator can review it now.";
      showDialog(["Application saved. A coordinator can review it now."]);
      updateSignupFlow();
    } catch(err) {
      if ($("#createMessage")) $("#createMessage").textContent = err.message;
      showDialog([err.message]);
    }
  });

  on("#profilePhotoInput", "change", async (e) => {
    const file = e.target.files?.[0];
    const message = $("#profileMessage");
    if (!file) return;
    try {
      const profilePhoto = await fileToDataUrl(file);
      const data = await apiRequest("update_profile_photo", { profilePhoto });
      applyState(data);
      if (message) message.textContent = "Photo updated.";
    } catch(err) {
      if (message) message.textContent = err.message;
      e.target.value = "";
    }
  });

  on("#createShiftForm", "submit", async (e) => {
    e.preventDefault();
    try {
      if (!confirm("Create this shift?")) return;
      const beforeIds = new Set(shifts.map(shift => String(shift.id)));
      const data = await apiRequest("create_shift", {
        department: $("#newShiftDepartment").value, title: $("#newShiftTitle").value,
        day: $("#newShiftDay").value, time: $("#newShiftTime").value,
        hours: $("#newShiftHours").value, capacity: $("#newShiftCapacity").value, note: $("#newShiftNote").value
      });
      const createdShift = (data.shifts || []).find(shift => !beforeIds.has(String(shift.id)));
      focusedManagedShiftId = createdShift?.id || null;
      applyState(data);
      e.target.reset();
      applyShiftTemplate(0);
      if ($("#createShiftMessage")) $("#createShiftMessage").textContent = "Shift created.";
      showDialog(["Shift created."]);
    } catch(err) {
      if ($("#createShiftMessage")) $("#createShiftMessage").textContent = err.message;
    }
  });
  on("#discordLoginBtn", "click", () => {
    window.location.href = `${configuredApiBase}?action=discord_login`;
  });

  on("#shiftTemplateSelect", "change", (e) => applyShiftTemplate(Number(e.target.value || 0)));
  on("#manageShiftDepartmentFilter", "change", () => {
    focusedManagedShiftId = null;
    renderShiftManagement();
    renderOnShiftList();
  });
  on("#rosterSearch", "input", renderManagement);
  on("#rosterDepartmentFilter", "change", renderManagement);
  on("#rosterShirtFilter", "change", renderManagement);
  on("#rosterProfileFilter", "change", renderManagement);
  on("#manageShiftSelect", "change", renderShiftManagement);
  on("#assignVolunteerBtn", "click", () => assignVolunteerToManagedShift(false));
  on("#revokeVolunteerBtn", "click", () => revokeVolunteerFromManagedShift(false));
  on("#deleteShiftBtn", "click", deleteManagedShift);
  on("#exportScheduleBtn", "click", exportScheduleCsv);
  on("#exportAllergiesBtn", "click", exportAllergiesCsv);
  on("#exportMode", "change", updateExportControls);
  on("#saveAvailabilityBtn", "click", saveAvailability);
  on("#savePreferencesBtn", "click", savePreferences);
  on("#saveMealWindowsBtn", "click", saveMealWindows);
  on("#downloadShiftTemplateBtn", "click", downloadShiftTemplate);
  on("#importShiftsBtn", "click", importShiftWorkbook);
  on("#sendDiscordDmBtn", "click", sendDiscordDmToVolunteer);
  on("#guestFlightForm", "submit", saveGuestFlight);
  on("#refreshGuestFlightsBtn", "click", refreshGuestFlights);
  on("#guestFlightList", "change", (event) => {
    const select = event.target.closest("[data-flight-assignee]");
    if (select) updateGuestFlightAssignee(select);
  });
  on("#closeVolunteerProfileBtn", "click", closeVolunteerProfile);
  on("#volunteerProfileForm", "submit", saveVolunteerManagementProfile);
  on("#volunteerProfileNoteForm", "submit", addVolunteerManagementNote);
  on("#volunteerProfileBlacklistToggle", "click", toggleVolunteerBlacklist);
  on("#vendorHallForm", "submit", saveVendorHallAssignment);
  on("#vendorHallClearBtn", "click", clearVendorHallAssignment);
  on("#vendorHallMap", "click", (event) => {
    const spot = event.target.closest("[data-vendor-spot]");
    if (spot) selectVendorHallSpot(spot.dataset.vendorSpot);
  });
  on("#newIncidentBtn", "click", startNewIncident);
  on("#clearIncidentBtn", "click", startNewIncident);
  on("#incidentForm", "submit", saveSafetyIncident);
  on("#incidentSearch", "input", renderIncidentList);
  on("#incidentStatusFilter", "change", renderIncidentList);
  on("#incidentSeverityFilter", "change", renderIncidentList);
  on("#uploadEvidenceBtn", "click", uploadSafetyEvidence);
  on("#attachEvidenceUrlBtn", "click", attachSafetyEvidenceUrl);
  on("#shiftAlertRuleForm", "submit", saveShiftAlertRule);
  on("#alertShiftSelect", "change", renderSelectedAlertRule);
  on("#runShiftAlertsBtn", "click", runShiftAlertScan);
  on("#createHotelRoomBtn", "click", createHotelRoom);
  on("#hotelGenderFilter", "change", renderHotels);
  on("#refreshAvailabilityBtn", "click", () => {
    renderAdminAvailability();
    showDialog(["Availability list refreshed."]);
  });
  on("#recommendScheduleBtn", "click", () => {
    latestRecommendations = buildScheduleRecommendations();
    renderRecommendedSchedule();
    showDialog([`Built ${latestRecommendations.length} schedule recommendation${latestRecommendations.length === 1 ? "" : "s"}.`]);
  });
  on("#submitVolunteerBtn", "click", saveMySchedule);
  on("#volunteerShiftSort", "change", renderShiftList);
  on("#volClockInBtn", "click", () => updateClockStatus("", true, "#volunteerClockMessage", false));
  on("#volClockOutBtn", "click", () => updateClockStatus("", false, "#volunteerClockMessage", false));
  on("#adminClockInBtn", "click", () => updateClockStatus($("#adminClockLookup")?.value || "", true, "#adminClockMessage", true));
  on("#adminClockOutBtn", "click", () => updateClockStatus($("#adminClockLookup")?.value || "", false, "#adminClockMessage", true));

  on("#logoutBtn", "click", async () => {
    await apiRequest("logout", {});
    applyState({ user: null, volunteers: [], shifts: [] });
  });

  $$(".tab").forEach(tab => {
    tab.addEventListener("click", () => switchView(tab.dataset.view));
  });
}

function updateAuthView() {
  const isSignedIn = !!currentUser;

  if ($("#authView")) $("#authView").hidden = isSignedIn;
  if ($(".app-header")) $(".app-header").hidden = !isSignedIn;
  if ($("main")) $("main").hidden = !isSignedIn;

  document.body.classList.toggle("logged-in", isSignedIn);

  if (isSignedIn) {
    const accountRank = needsApplication(currentUser) ? "Application pending" : (currentUser.rank || "Volunteer");
    if ($("#accountLabel")) $("#accountLabel").textContent = `${currentUser.name} (${accountRank})`;
    if ($("#tabGuestRelations")) $("#tabGuestRelations").hidden = !currentUser.canGuestRelations;
    if ($("#tabSafety")) $("#tabSafety").hidden = !currentUser.canSafety;
    if ($("#tabVendorHall")) $("#tabVendorHall").hidden = !currentUser.canVendorHall;

    if (needsApplication(currentUser)) {
      if ($("#tabGuestRelations")) $("#tabGuestRelations").hidden = true;
      if ($("#tabSafety")) $("#tabSafety").hidden = true;
      if ($("#tabVendorHall")) $("#tabVendorHall").hidden = true;
      if ($("#tabManagement")) $("#tabManagement").hidden = true;
      if ($("#tabAdmin")) $("#tabAdmin").hidden = true;
      $$(".tab").forEach(tab => tab.hidden = true);
      switchView("applicationView");
    } else if (isManagementUser(currentUser)) {
      $$(".tab").forEach(tab => tab.hidden = false);
      if ($("#tabGuestRelations")) $("#tabGuestRelations").hidden = !currentUser.canGuestRelations;
      if ($("#tabSafety")) $("#tabSafety").hidden = !currentUser.canSafety;
      if ($("#tabVendorHall")) $("#tabVendorHall").hidden = !currentUser.canVendorHall;
      if ($("#tabManagement")) $("#tabManagement").hidden = false;
      if ($("#tabAdmin")) $("#tabAdmin").hidden = !isFullAdmin(currentUser);
      switchView("managementView");
    } else {
      $$(".tab").forEach(tab => tab.hidden = false);
      if ($("#tabGuestRelations")) $("#tabGuestRelations").hidden = !currentUser.canGuestRelations;
      if ($("#tabSafety")) $("#tabSafety").hidden = !currentUser.canSafety;
      if ($("#tabVendorHall")) $("#tabVendorHall").hidden = !currentUser.canVendorHall;
      if ($("#tabManagement")) $("#tabManagement").hidden = true;
      if ($("#tabAdmin")) $("#tabAdmin").hidden = true;
      switchView(currentUser.canSafety ? "safetyView" : currentUser.canGuestRelations ? "guestRelationsView" : currentUser.canVendorHall ? "vendorHallView" : "volunteerView");
    }
  }
}

function switchView(viewId) {
  $$(".tab").forEach(tab => tab.classList.toggle("active", tab.dataset.view === viewId));
  $$(".view").forEach(view => view.classList.toggle("active", view.id === viewId));
  document.body.dataset.view = viewId;
}

// PUT YOUR ACTUAL DEPARTMENTS HERE:
const DELTA_H_DEPTS = ["Con Ops", "Con Suite", "Registration/Info Desk", "Tech Maids", "Guest Relations", "Safety", "Winged Monkeys", "Events", "E-Gaming"];

function populateDepartmentSelects() {
  const opts = DELTA_H_DEPTS.map(d => `<option value="${d}">${d}</option>`).join("");
  if ($("#applyDepartment")) $("#applyDepartment").innerHTML += opts;
  if ($("#newShiftDepartment")) $("#newShiftDepartment").innerHTML = opts;
  const templateSelect = $("#shiftTemplateSelect");
  if (templateSelect) {
    templateSelect.innerHTML = SHIFT_TEMPLATES.map((template, index) => `<option value="${index}">${escapeHtml(template.name)}</option>`).join("");
    applyShiftTemplate(0);
  }
}

function populateMealWindowControls() {
  [
    ["#breakfastWindow", "breakfast"],
    ["#lunchWindow", "lunch"],
    ["#dinnerWindow", "dinner"]
  ].forEach(([selector, key]) => {
    const select = $(selector);
    if (!select) return;
    select.innerHTML = MEAL_WINDOW_OPTIONS.map(option => `<option value="${escapeHtml(option)}">${escapeHtml(option)}</option>`).join("");
    select.value = mealWindows[key] || DEFAULT_MEAL_WINDOWS[key];
  });
}

function loadMealWindows() {
  try {
    const saved = JSON.parse(localStorage.getItem("deltaHMealWindows") || "{}");
    return { ...DEFAULT_MEAL_WINDOWS, ...saved };
  } catch {
    return { ...DEFAULT_MEAL_WINDOWS };
  }
}

function saveMealWindows() {
  mealWindows = {
    breakfast: $("#breakfastWindow")?.value || DEFAULT_MEAL_WINDOWS.breakfast,
    lunch: $("#lunchWindow")?.value || DEFAULT_MEAL_WINDOWS.lunch,
    dinner: $("#dinnerWindow")?.value || DEFAULT_MEAL_WINDOWS.dinner
  };
  localStorage.setItem("deltaHMealWindows", JSON.stringify(mealWindows));
  renderDailyCounts();
  showDialog(["Meal count windows saved."]);
}

function applyShiftTemplate(index) {
  const template = SHIFT_TEMPLATES[index] || SHIFT_TEMPLATES[0];
  if (!template) return;
  if ($("#newShiftTitle")) $("#newShiftTitle").value = template.title;
  if ($("#newShiftDay")) $("#newShiftDay").value = template.day;
  if ($("#newShiftTime")) $("#newShiftTime").value = template.time;
  if ($("#newShiftHours")) $("#newShiftHours").value = template.hours;
  if ($("#newShiftCapacity")) $("#newShiftCapacity").value = template.capacity;
  if ($("#newShiftNote")) $("#newShiftNote").value = template.note;
}

function managerVisibleShifts() {
  if (!isManagementUser(currentUser)) return [];
  if (isFullAdmin(currentUser)) return shifts;
  const department = currentUser.department || currentUser.applied_department || currentUser.appliedDepartment || "";
  return shifts.filter(shift => shift.department === department);
}

function selectedManagementDepartment() {
  const select = $("#manageShiftDepartmentFilter");
  if (!select) return "All departments";
  return select.value || "All departments";
}

function departmentFilteredManagerShifts() {
  const visible = managerVisibleShifts();
  const department = selectedManagementDepartment();
  if (department === "All departments") return visible;
  return visible.filter(shift => shift.department === department);
}

function renderManagementDepartmentFilter() {
  const select = $("#manageShiftDepartmentFilter");
  if (!select || !isManagementUser(currentUser)) return;
  const current = select.value || "All departments";
  const departments = Array.from(new Set(managerVisibleShifts().map(shift => shift.department).filter(Boolean))).sort();
  const options = isFullAdmin(currentUser)
    ? ["All departments", ...departments]
    : departments.length
      ? departments
      : [currentUser.department || currentUser.applied_department || currentUser.appliedDepartment || "My department"];
  select.innerHTML = options.map(department => `<option value="${escapeHtml(department)}">${escapeHtml(department)}</option>`).join("");
  select.value = options.includes(current) ? current : options[0];
  select.disabled = !isFullAdmin(currentUser);
}

function managerVisibleUsers() {
  if (!isManagementUser(currentUser)) return [];
  if (isFullAdmin(currentUser)) return users;
  const department = currentUser.department || currentUser.applied_department || currentUser.appliedDepartment || "";
  return users.filter(user => {
    const userDepartment = user.department || user.applied_department || user.appliedDepartment || "";
    return userDepartment === department || String(user.id) === String(currentUser.id);
  });
}

function userDepartment(user) {
  return user.department || user.applied_department || user.appliedDepartment || "";
}

function updateManagedDepartmentSelect() {
  const select = $("#newShiftDepartment");
  if (!select || !isManagementUser(currentUser)) return;
  if (isFullAdmin(currentUser)) {
    const current = select.value;
    select.innerHTML = DELTA_H_DEPTS.map(d => `<option value="${escapeHtml(d)}">${escapeHtml(d)}</option>`).join("");
    if (DELTA_H_DEPTS.includes(current)) select.value = current;
    select.disabled = false;
    return;
  }

  const department = currentUser.department || "";
  select.innerHTML = department
    ? `<option value="${escapeHtml(department)}">${escapeHtml(department)}</option>`
    : `<option value="">Assign your department first</option>`;
  select.value = department;
  select.disabled = true;
}

function buildAvailabilityControls() {
  renderApplicationDayOptions();
  renderAvailabilityPicker("#applyAvailabilityGrid", "apply");
  renderAvailabilityPicker("#profileAvailabilityGrid", "profile");
  updateSignupFlow();
}

function renderApplicationDayOptions() {
  const container = $("#applyDayOptions");
  if (!container) return;
  container.innerHTML = APPLICATION_DAY_OPTIONS.map(day => `
    <label class="check-row day-option">
      <input type="checkbox" data-application-day value="${escapeHtml(day)}">
      <span>${escapeHtml(day)}</span>
    </label>
  `).join("");
}

function renderAvailabilityPicker(selector, scope) {
  const container = $(selector);
  if (!container) return;

  container.innerHTML = AVAILABILITY_DAYS.map(day => `
    <article class="availability-day">
      <h4>${escapeHtml(day)}</h4>
      <div class="availability-options">
        ${AVAILABILITY_HOURS.map(hour => `
          <label class="check-row">
            <input type="checkbox" data-availability-scope="${scope}" data-availability-day="${day}" value="${hour}">
            <span>${hour}</span>
          </label>
        `).join("")}
      </div>
    </article>
  `).join("");
}

function updateSignupFlow() {
  const form = $("#createAccountForm");
  if (!form) return;

  const identityDone = Boolean(
    $("#applyDiscord")?.value.trim() &&
    $("#applyDob")?.value &&
    $("#applyShirtSize")?.value
  );
  const teamDone = identityDone && Boolean(
    $("#applyDepartment")?.value &&
    $("#applyRequestedHours")?.value &&
    collectApplicationDays().length
  );
  const availabilityDone = teamDone && availabilityHasAny(collectAvailability("apply"));
  const logisticsDone = availabilityDone && Boolean($("#applyHotel")?.value && $("#applyGender")?.value && $("#applyAllergies")?.value.trim());
  const stepStates = [true, identityDone, teamDone, availabilityDone];

  $$("[data-signup-step]").forEach((section, index) => {
    const active = stepStates[index];
    section.classList.toggle("is-active", active);
    section.classList.toggle("is-locked", !active);
    section.querySelectorAll("input, select, textarea, button").forEach(control => {
      control.disabled = !active;
    });
  });

  $$(".signup-progress span").forEach((dot, index) => {
    dot.classList.toggle("is-active", stepStates[index]);
    dot.classList.toggle("is-complete", index === 0 ? identityDone : index === 1 ? teamDone : index === 2 ? availabilityDone : logisticsDone);
  });

  const submit = $("#submitApplicationBtn");
  if (submit) submit.disabled = !logisticsDone;
}

function buildAdminAvailabilityControls() {
  const dayButtons = $("#availabilityDayButtons");
  if (dayButtons) {
    dayButtons.innerHTML = AVAILABILITY_DAYS.map(day => `
      <button class="segment-button ${day === selectedAvailabilityDay ? "active" : ""}" type="button" data-availability-day-button="${day}">${day}</button>
    `).join("");
    $$("[data-availability-day-button]").forEach(button => {
      button.addEventListener("click", () => {
        selectedAvailabilityDay = button.dataset.availabilityDayButton;
        buildAdminAvailabilityControls();
        renderAdminAvailability();
      });
    });
  }

  const hourFilter = $("#availabilityHourFilter");
  if (hourFilter) {
    const current = hourFilter.value || "All hours";
    hourFilter.innerHTML = [
      `<option value="All hours">All hours</option>`,
      ...AVAILABILITY_HOURS.map(hour => `<option value="${hour}">${hour}</option>`)
    ].join("");
    hourFilter.value = current;
  }
}

// Inside renderManagement():
const depts = DELTA_H_DEPTS; // Ensure this is linked to the constant

// ==========================================
// THE RENDERING ENGINE
// ==========================================

function renderAll() {
  renderApplicationForm();
  if (needsApplication(currentUser)) return;
  renderProfile();
  renderPreferenceForm();
  renderVolunteer();
  renderAvailabilityForm();
  updateManagedDepartmentSelect();
  renderManagementOverview();
  renderManagement();
  renderShiftManagement();
  renderDailyCounts();
  renderHotels();
  renderDiscordDmTools();
  renderSystemLogs();
  renderGuestFlights();
  renderSafety();
  renderVendorHall();
  renderShiftAlerts();
  renderAdmin();
  renderAdminAvailability();
  renderRecommendedSchedule();
  renderOnShiftList();
  updateExportControls();
}

function renderApplicationForm() {
  const form = $("#createAccountForm");
  if (!form || !currentUser) return;

  if (applicationFormHydratedUserId !== String(currentUser.id)) {
    const dates = applicationDaysForUser(currentUser);
    setValue("#applyDiscord", currentUser.discord || currentUser.discord_username || currentUser.discordUsername || "");
    setValue("#applyDob", currentUser.date_of_birth || currentUser.dateOfBirth || "");
    setValue("#applyShirtSize", currentUser.shirt_size || currentUser.shirtSize || "");
    setValue("#applyDepartment", currentUser.applied_department || currentUser.appliedDepartment || currentUser.department || "");
    setValue("#applyRequestedHours", currentUser.requested_hours || currentUser.requestedHours || "");
    setValue("#applyHotel", currentUser.hotel_needed || currentUser.hotelNeeded || "");
    setValue("#applyGender", currentUser.gender || "");
    setValue("#applyAllergies", currentUser.allergies || "");
    setValue("#applyPreviousExperience", currentUser.previous_experience || currentUser.previousExperience || "");
    setValue("#applySkills", currentUser.skills || "");
    setValue("#applyAdditionalNotes", currentUser.additional_notes || currentUser.additionalNotes || currentUser.buddyRequest || "");

    $$("[data-application-day]").forEach(input => {
      input.checked = dates.includes(input.value);
    });

    const availability = normalizeAvailability(currentUser.availability);
    $$('[data-availability-scope="apply"]').forEach(input => {
      input.checked = availability[input.dataset.availabilityDay]?.includes(input.value) || false;
    });

    applicationFormHydratedUserId = String(currentUser.id);
  }

  if ($("#applicationStatus")) {
    const submittedAt = currentUser.application_submitted_at || currentUser.applicationSubmittedAt || "";
    $("#applicationStatus").textContent = submittedAt
      ? "Application saved. You can update it until a coordinator approves your account."
      : "After you submit, your account stays pending until a coordinator approves it.";
  }

  updateSignupFlow();
}

function setValue(selector, value) {
  const element = $(selector);
  if (element) element.value = value ?? "";
}

function applicationDaysForUser(user) {
  const raw = String(user.dates_available || user.datesAvailable || "").trim();
  const days = raw
    ? raw.split(/\s*[,;|]\s*/).map(day => day.trim()).filter(Boolean)
    : [];
  if ((user.wed_loadout || user.wedLoadout) && !days.includes("Wednesday - Truck Loading")) {
    days.push("Wednesday - Truck Loading");
  }
  if ((user.sun_loadout || user.sunLoadout) && !days.includes("Sunday Load-out")) {
    days.push("Sunday Load-out");
  }
  return days.filter(day => APPLICATION_DAY_OPTIONS.includes(day));
}

function renderManagementOverview() {
  const overview = $("#managementOverview");
  if (!overview) return;
  overview.hidden = !isManagementUser(currentUser);
  if (!isManagementUser(currentUser)) return;

  const visibleUsers = managerVisibleUsers();
  const visibleShifts = managerVisibleShifts();
  const pendingCount = visibleUsers.filter(user => user.status === "pending").length;
  const approvedCount = visibleUsers.filter(user => user.status === "approved").length;
  const assignedVolunteerIds = new Set();
  let openSlots = 0;

  visibleShifts.forEach(shift => {
    const assigned = visibleUsers.filter(user => userHasShift(user, shift.id));
    assigned.forEach(user => assignedVolunteerIds.add(String(user.id)));
    openSlots += Math.max(Number(shift.capacity || 0) - assigned.length, 0);
  });

  overview.innerHTML = `
    <article class="stat"><span>Pending applications</span><strong>${pendingCount}</strong></article>
    <article class="stat"><span>Approved volunteers</span><strong>${approvedCount}</strong></article>
    <article class="stat"><span>Created shifts</span><strong>${visibleShifts.length}</strong></article>
    <article class="stat"><span>Open shift spots</span><strong>${openSlots}</strong></article>
  `;
}

function renderProfile() {
  const preview = $("#profilePhotoPreview");
  if (!preview || !currentUser) return;
  preview.src = currentUser.profile_photo || currentUser.profilePhoto || defaultProfilePhoto(currentUser.name);
}

function renderPreferenceForm() {
  const panel = $(".preferences-panel");
  if (!panel || !currentUser) return;
  panel.hidden = false;
  if ($("#profileBuddyRequest")) $("#profileBuddyRequest").value = currentUser.buddyRequest || currentUser.buddy_request || currentUser.friend || "";
  if ($("#profileCarpoolRequest")) $("#profileCarpoolRequest").value = currentUser.carpoolRequest || currentUser.carpool_request || "";
}

function renderVolunteer() {
  if (!currentUser) return;
  renderShiftList();
  renderMySchedule();
}

function renderShiftList() {
  const list = $("#shiftList");
  if (!list) return;

  const dept = currentUser.department || currentUser.applied_department || currentUser.appliedDepartment || "";
  const sortMode = $("#volunteerShiftSort")?.value || "day";
  const visibleShifts = sortShifts(shifts.filter(shift => !dept || shift.department === dept), sortMode);

  if ($("#volDepartmentHeader")) {
    $("#volDepartmentHeader").textContent = dept ? `${dept} shift pickup` : "Shift pickup";
  }

  if ($("#selectionSummary")) {
    const selected = shifts.filter(shift => selectedShiftIds.has(String(shift.id)));
    const hours = selected.reduce((total, shift) => total + Number(shift.hours || 0), 0);
    $("#selectionSummary").textContent = selected.length
      ? `${selected.length} shift${selected.length === 1 ? "" : "s"} selected, ${hours} hours total.`
      : "No shifts selected yet.";
  }

  let currentGroup = "";
  list.innerHTML = visibleShifts.map(shift => {
    const group = sortMode === "department"
      ? (shift.department || "Unassigned")
      : sortMode === "title"
        ? "All shifts"
        : (shift.day || shift.shift_day || "Unscheduled");
    const divider = group !== currentGroup
      ? `<div class="shift-day-divider">${escapeHtml(group)}</div>`
      : "";
    currentGroup = group;
    const assigned = users.filter(user => (user.shiftIds || []).map(String).includes(String(shift.id)));
    const remaining = Math.max(Number(shift.capacity || 0) - assigned.length, 0);
    const selected = selectedShiftIds.has(String(shift.id));
    const full = remaining === 0 && !selected;
    return `
      ${divider}
      <article class="shift-card ${selected ? "selected" : ""} ${full ? "full" : ""}">
        <div>
          <p class="eyebrow">${escapeHtml(shift.department)}</p>
          <h3>${escapeHtml(shift.title)}</h3>
        </div>
        <div>
          <div class="shift-meta">
            <span>${escapeHtml(shift.day || shift.shift_day)}, ${escapeHtml(shift.time || shift.shift_time)}</span>
            <span>${escapeHtml(shift.hours)} hours</span>
            <span>${remaining} of ${escapeHtml(shift.capacity)} spots open</span>
          </div>
          <div class="badge-row">
            <span class="badge">${escapeHtml(shift.note || "Shift")}</span>
            ${shiftMatchesAvailability(currentUser, shift) ? `<span class="badge approved">Available</span>` : `<span class="badge pending">Outside availability</span>`}
          </div>
        </div>
        <button type="button" data-shift-id="${escapeHtml(shift.id)}" ${full ? "disabled" : ""}>${selected ? "Selected" : full ? "Full" : "Add shift"}</button>
      </article>
    `;
  }).join("") || `<p class="summary">No ${escapeHtml(dept || "assigned department")} shifts are available yet. If you expected to see a shift, check that your assigned department matches the shift department.</p>`;

  $$("#shiftList button[data-shift-id]").forEach(button => {
    button.addEventListener("click", () => {
      const shiftId = String(button.dataset.shiftId);
      if (selectedShiftIds.has(shiftId)) selectedShiftIds.delete(shiftId);
      else selectedShiftIds.add(shiftId);
      renderShiftList();
      renderMySchedule();
    });
  });
}

function renderMySchedule() {
  const summary = $("#myScheduleSummary");
  const list = $("#myShiftList");
  if (!summary || !list || !currentUser) return;

  const assigned = shifts.filter(shift => (currentUser.shiftIds || []).map(String).includes(String(shift.id)));
  const selected = shifts.filter(shift => selectedShiftIds.has(String(shift.id)));
  const hours = assigned.reduce((total, shift) => total + Number(shift.hours || 0), 0);

  if ($("#myClockStatus")) {
    $("#myClockStatus").textContent = currentUser.clockedIn ? "Clocked in" : "Clocked out";
  }

  summary.innerHTML = `
    <article class="mini-stat"><span>Saved shifts</span><strong>${assigned.length}</strong></article>
    <article class="mini-stat"><span>Saved hours</span><strong>${hours}</strong></article>
    <article class="mini-stat"><span>Pending picks</span><strong>${selected.length}</strong></article>
  `;

  list.innerHTML = assigned.length ? assigned.map(shift => `
    <article class="my-shift-card">
      <div><strong>${escapeHtml(shift.title)}</strong><span>${escapeHtml(shift.department)}</span></div>
      <span>${escapeHtml(shift.day || shift.shift_day)}, ${escapeHtml(shift.time || shift.shift_time)}</span>
      <span class="badge">${escapeHtml(shift.hours)} hours</span>
    </article>
  `).join("") : `<p class="summary">No saved shifts yet. Pick shifts below and save your schedule.</p>`;
}

function renderAvailabilityForm() {
  const panel = $(".availability-panel");
  if (!panel || !currentUser) return;
  panel.hidden = isManagementUser(currentUser);
  const availability = normalizeAvailability(currentUser.availability);
  $$('[data-availability-scope="profile"]').forEach(input => {
    input.checked = availability[input.dataset.availabilityDay]?.includes(input.value) || false;
  });
}

async function saveAvailability() {
  try {
    const data = await apiRequest("save_availability", { availability: collectAvailability("profile") });
    applyState(data);
    if ($("#availabilityMessage")) $("#availabilityMessage").textContent = "Availability saved.";
    showDialog(["Availability saved."]);
  } catch(err) {
    if ($("#availabilityMessage")) $("#availabilityMessage").textContent = err.message;
  }
}

async function savePreferences() {
  try {
    const data = await apiRequest("save_preferences", {
      buddyRequest: $("#profileBuddyRequest")?.value || "",
      carpoolRequest: $("#profileCarpoolRequest")?.value || ""
    });
    applyState(data);
    if ($("#preferenceMessage")) $("#preferenceMessage").textContent = "Preferences saved.";
    showDialog(["Buddy and carpool preferences saved."]);
  } catch(err) {
    if ($("#preferenceMessage")) $("#preferenceMessage").textContent = err.message;
  }
}

async function saveMySchedule() {
  try {
    const data = await apiRequest("save_schedule", { shiftIds: Array.from(selectedShiftIds) });
    applyState(data);
    showDialog(["Schedule saved."]);
  } catch(err) {
    showDialog([err.message]);
  }
}

function renderManagement() {
  const pendingBody = $("#pendingApplicationsBody");
  const rosterBody = $("#rosterManagementBody");
  if (!pendingBody || !rosterBody) return;

  pendingBody.innerHTML = "";
  rosterBody.innerHTML = "";

  const depts = DELTA_H_DEPTS;
  const ranks = ["Volunteer", "Staff", "Coordinator", "Admin"];
  const visibleUsers = managerVisibleUsers();
  const activeUsers = visibleUsers.filter(user => user.status !== "pending");
  const rosterSearch = String($("#rosterSearch")?.value || "").trim().toLowerCase();
  const departmentFilter = $("#rosterDepartmentFilter");
  const shirtFilter = $("#rosterShirtFilter")?.value || "all";
  const profileFilter = $("#rosterProfileFilter")?.value || "all";

  if (departmentFilter) {
    const previousDepartment = departmentFilter.value || "all";
    const departments = Array.from(new Set(activeUsers.map(userDepartment).filter(Boolean))).sort();
    departmentFilter.innerHTML = [
      `<option value="all">All departments</option>`,
      ...departments.map(department => `<option value="${escapeHtml(department)}">${escapeHtml(department)}</option>`)
    ].join("");
    departmentFilter.value = departments.includes(previousDepartment) ? previousDepartment : "all";
  }

  const shirtSummary = $("#shirtSizeSummary");
  if (shirtSummary) {
    const shirtCounts = new Map();
    activeUsers
      .filter(user => user.status === "approved")
      .forEach(user => {
        const size = String(user.shirtSize || user.shirt_size || "").trim().toUpperCase();
        if (!size) return;
        const current = shirtCounts.get(size) || { total: 0, picked: 0 };
        current.total += 1;
        if (user.shirtPickedUp) current.picked += 1;
        shirtCounts.set(size, current);
      });
    const sizeOrder = ["XS", "S", "M", "L", "XL", "2XL", "3XL", "4XL", "5XL"];
    const sizes = Array.from(shirtCounts.keys()).sort((a, b) => {
      const aIndex = sizeOrder.indexOf(a);
      const bIndex = sizeOrder.indexOf(b);
      return (aIndex < 0 ? 99 : aIndex) - (bIndex < 0 ? 99 : bIndex) || a.localeCompare(b);
    });
    shirtSummary.innerHTML = sizes.length
      ? sizes.map(size => {
          const count = shirtCounts.get(size);
          return `<span class="shirt-summary-chip"><strong>${escapeHtml(size)}</strong><span>${count.picked}/${count.total} picked up</span></span>`;
        }).join("")
      : `<span class="summary">No T-shirt sizes recorded yet.</span>`;
  }

  const profileSummary = $("#volunteerProfileDashboardSummary");
  if (profileSummary) {
    const profiled = activeUsers.filter(user => user.managementProfile).length;
    const inviteBack = activeUsers.filter(user => ["Strongly invite back", "Invite back"].includes(user.managementProfile?.next_year_recommendation)).length;
    const coaching = activeUsers.filter(user => user.managementProfile?.next_year_recommendation === "Invite with coaching").length;
    const doNotInvite = activeUsers.filter(user => user.managementProfile?.next_year_recommendation === "Do not invite back").length;
    profileSummary.innerHTML = `
      <span class="shirt-summary-chip"><strong>${profiled}/${activeUsers.length}</strong><span>profiles completed</span></span>
      <span class="shirt-summary-chip"><strong>${inviteBack}</strong><span>invite back</span></span>
      <span class="shirt-summary-chip"><strong>${coaching}</strong><span>with coaching</span></span>
      <span class="shirt-summary-chip"><strong>${doNotInvite}</strong><span>do not invite</span></span>`;
  }

  visibleUsers.forEach(u => {
    const preferenceHtml = [u.buddyRequest || u.buddy_request || u.friend, u.carpoolRequest || u.carpool_request]
      .filter(Boolean)
      .map(item => `<small>${escapeHtml(item)}</small>`)
      .join("<br>");
    if (u.status === 'pending') {
      pendingBody.innerHTML += `
        <tr>
          <td>${rosterPersonHtml(u)}${preferenceHtml ? `<div class="preference-note">${preferenceHtml}</div>` : ""}</td>
          <td>
            <strong>${escapeHtml(u.applied_department || u.appliedDepartment || 'None')}</strong>
            <div class="summary">${escapeHtml(u.requestedHours || u.requested_hours || '0')} requested hours</div>
            <div class="summary">${escapeHtml(u.dates_available || u.datesAvailable || 'No days selected')}</div>
          </td>
          <td>${escapeHtml(availabilitySummary(u.availability))}</td>
          <td>${applicationDetailsHtml(u)}</td>
          <td>${escapeHtml(u.hotel_needed || u.hotelNeeded || 'No')}</td>
          <td>
            <button class="primary-button" onclick="updateUserField(${u.id}, 'status', 'approved')" style="padding: 4px 8px; font-size: 0.8rem;">Approve</button>
            <button class="quiet-button" onclick="updateUserField(${u.id}, 'status', 'denied')" style="padding: 4px 8px; font-size: 0.8rem;">Deny</button>
          </td>
        </tr>`;
    } else {
      const selectedDepartment = departmentFilter?.value || "all";
      const searchableText = [u.name, u.email, u.discord, u.discord_username, u.department, u.applied_department]
        .map(value => String(value || "").toLowerCase())
        .join(" ");
      if (rosterSearch && !searchableText.includes(rosterSearch)) return;
      if (selectedDepartment !== "all" && userDepartment(u) !== selectedDepartment) return;
      if (shirtFilter === "picked" && !u.shirtPickedUp) return;
      if (shirtFilter === "pending" && u.shirtPickedUp) return;
      const recommendation = u.managementProfile?.next_year_recommendation || "";
      if (profileFilter === "no-profile" && u.managementProfile) return;
      if (!["all", "no-profile"].includes(profileFilter) && recommendation !== profileFilter) return;

      // Build Dropdowns matching the user's current data
      const deptOptions = depts.map(d => `<option value="${escapeHtml(d)}" ${u.department === d ? 'selected' : ''}>${escapeHtml(d)}</option>`).join("");
      const rankOptions = ranks.map(r => `<option value="${escapeHtml(r)}" ${u.rank === r ? 'selected' : ''}>${escapeHtml(r)}</option>`).join("");
      const adminOnly = isFullAdmin(currentUser) ? "" : "disabled";
      const shirtSize = String(u.shirtSize || u.shirt_size || "").trim() || "—";
      const pickupTime = u.shirtPickedUpAt ? formatIncidentDate(u.shirtPickedUpAt) : "";

      rosterBody.innerHTML += `
        <tr>
          <td>${rosterPersonHtml(u)}${preferenceHtml ? `<div class="preference-note">${preferenceHtml}</div>` : ""}</td>
          <td>
            <select ${adminOnly} onchange="updateUserField(${u.id}, 'rank', this.value)" style="padding: 4px; border-radius: 4px; border: 1px solid var(--border);">
              <option value="" disabled ${!u.rank ? 'selected' : ''}>Select Rank</option>
              ${rankOptions}
            </select>
          </td>
          <td>
            <select ${adminOnly} onchange="updateUserField(${u.id}, 'department', this.value)" style="padding: 4px; border-radius: 4px; border: 1px solid var(--border);">
              <option value="" ${!u.department ? 'selected' : ''}>Unassigned</option>
              ${deptOptions}
            </select>
          </td>
          <td>
            <div class="shirt-pickup-cell">
              <span class="shirt-size-badge" title="Requested T-shirt size">${escapeHtml(shirtSize)}</span>
              <button class="shirt-pickup-toggle ${u.shirtPickedUp ? "is-picked" : ""}" type="button"
                aria-pressed="${u.shirtPickedUp ? "true" : "false"}"
                aria-label="Mark ${escapeHtml(u.name)}'s T-shirt as ${u.shirtPickedUp ? "not picked up" : "picked up"}"
                onclick="updateShirtPickup(${u.id}, ${u.shirtPickedUp ? "false" : "true"}, this)">
                <span class="pickup-check" aria-hidden="true">${u.shirtPickedUp ? "✓" : ""}</span>
                ${u.shirtPickedUp ? "Picked up" : "Not picked up"}
              </button>
              ${pickupTime ? `<small>Updated ${escapeHtml(pickupTime)}</small>` : ""}
            </div>
          </td>
          <td><span class="badge ${escapeHtml(u.status)}">${escapeHtml(u.status)}</span></td>
          <td>
            <button class="quiet-button roster-action" type="button" onclick="openVolunteerProfile(${u.id})">
              ${u.managementProfile || (u.managementNotes || []).length ? "View profile" : "Create profile"}
            </button>
            ${u.managementProfile ? `<div class="profile-recommendation">${escapeHtml(u.managementProfile.next_year_recommendation || "Undecided")}</div>` : ""}
          </td>
          <td><div>${escapeHtml(u.role)}</div></td>
        </tr>`;
    }
  });

  if (pendingBody.innerHTML === "") pendingBody.innerHTML = "<tr><td colspan='6' style='text-align:center;'>No pending applications.</td></tr>";
  if (rosterBody.innerHTML === "") rosterBody.innerHTML = "<tr><td colspan='7' style='text-align:center;'>No volunteers match these filters.</td></tr>";
}

window.openVolunteerProfile = (userId) => {
  selectedVolunteerProfileId = Number(userId);
  renderVolunteerManagementProfile();
  const dialog = $("#volunteerProfileDialog");
  if (dialog && !dialog.open) dialog.showModal();
};

function closeVolunteerProfile() {
  $("#volunteerProfileDialog")?.close();
  selectedVolunteerProfileId = null;
}

function selectedVolunteerProfileUser() {
  return managerVisibleUsers().find(user => Number(user.id) === Number(selectedVolunteerProfileId)) || null;
}

function renderVolunteerManagementProfile() {
  const user = selectedVolunteerProfileUser();
  if (!user) return;
  const profile = user.managementProfile || {};
  const notes = Array.isArray(user.managementNotes) ? user.managementNotes : [];
  if ($("#volunteerProfileName")) $("#volunteerProfileName").textContent = user.name || "Volunteer profile";
  if ($("#volunteerProfileMeta")) {
    $("#volunteerProfileMeta").textContent = [userDepartment(user), user.discord || user.discord_username, user.email].filter(Boolean).join(" • ");
  }
  if ($("#volunteerProfileStrengths")) $("#volunteerProfileStrengths").value = profile.strengths || "";
  if ($("#volunteerProfileGrowth")) $("#volunteerProfileGrowth").value = profile.growth_areas || "";
  if ($("#volunteerProfileRecommendation")) $("#volunteerProfileRecommendation").value = profile.next_year_recommendation || "Undecided";
  if ($("#volunteerProfileSummary")) $("#volunteerProfileSummary").value = profile.private_summary || "";
  if ($("#volunteerProfileNoteYear") && !$("#volunteerProfileNoteYear").value) {
    $("#volunteerProfileNoteYear").value = new Date().getFullYear();
  }
  if ($("#volunteerProfileMessage")) {
    $("#volunteerProfileMessage").textContent = profile.updated_at
      ? `Last updated ${formatIncidentDate(profile.updated_at)}${profile.updated_by_name ? ` by ${profile.updated_by_name}` : ""}.`
      : "This profile is visible only to authorized management.";
  }
  const blacklistState = $("#volunteerProfileBlacklistState");
  const blacklistToggle = $("#volunteerProfileBlacklistToggle");
  const isBlacklisted = Boolean(user.blacklisted);
  if (blacklistState) {
    blacklistState.textContent = isBlacklisted
      ? "Blacklisted — this account is denied on its next request."
      : "Active — this account may sign in and use the portal.";
    blacklistState.classList.toggle("is-blacklisted", isBlacklisted);
  }
  if (blacklistToggle) {
    blacklistToggle.textContent = isBlacklisted ? "Restore access" : "Blacklist access";
    blacklistToggle.classList.toggle("primary-button", isBlacklisted);
    blacklistToggle.classList.toggle("danger-button", !isBlacklisted);
  }
  const list = $("#volunteerProfileNoteList");
  if (list) {
    list.innerHTML = notes.length ? notes.map(note => `
      <article class="profile-note-card">
        <div class="profile-note-meta">
          <span class="badge">${escapeHtml(note.note_type || "General")}</span>
          <strong>${escapeHtml(note.event_year || "")}</strong>
        </div>
        <p>${escapeHtml(note.note_text || "")}</p>
        <small>${escapeHtml(note.created_by_name || "Management")} • ${escapeHtml(formatIncidentDate(note.created_at || ""))}</small>
      </article>
    `).join("") : `<p class="summary">No management notes have been added yet.</p>`;
  }
}

async function saveVolunteerManagementProfile(event) {
  event.preventDefault();
  const user = selectedVolunteerProfileUser();
  if (!user) return;
  try {
    const data = await apiRequest("save_volunteer_management_profile", {
      userId: user.id,
      strengths: $("#volunteerProfileStrengths")?.value || "",
      growthAreas: $("#volunteerProfileGrowth")?.value || "",
      recommendation: $("#volunteerProfileRecommendation")?.value || "Undecided",
      privateSummary: $("#volunteerProfileSummary")?.value || ""
    });
    applyState(data);
    renderVolunteerManagementProfile();
    if ($("#volunteerProfileMessage")) $("#volunteerProfileMessage").textContent = "Volunteer management profile saved.";
  } catch (err) {
    if ($("#volunteerProfileMessage")) $("#volunteerProfileMessage").textContent = err.message;
  }
}

async function addVolunteerManagementNote(event) {
  event.preventDefault();
  const user = selectedVolunteerProfileUser();
  if (!user) return;
  try {
    const data = await apiRequest("add_volunteer_management_note", {
      userId: user.id,
      noteType: $("#volunteerProfileNoteType")?.value || "General",
      eventYear: $("#volunteerProfileNoteYear")?.value || new Date().getFullYear(),
      noteText: $("#volunteerProfileNoteText")?.value || ""
    });
    applyState(data);
    if ($("#volunteerProfileNoteText")) $("#volunteerProfileNoteText").value = "";
    renderVolunteerManagementProfile();
    if ($("#volunteerProfileMessage")) $("#volunteerProfileMessage").textContent = "Dated management note added.";
  } catch (err) {
    if ($("#volunteerProfileMessage")) $("#volunteerProfileMessage").textContent = err.message;
  }
}

async function toggleVolunteerBlacklist() {
  const user = selectedVolunteerProfileUser();
  if (!user) return;
  const willBlacklist = !user.blacklisted;
  if (willBlacklist && !confirm(`Blacklist ${user.name || "this volunteer"}? Their active session is revoked immediately and they lose all portal access until restored.`)) {
    return;
  }
  try {
    const data = await apiRequest("set_user_blacklist", { userId: user.id, blacklisted: willBlacklist });
    applyState(data);
    renderVolunteerManagementProfile();
    if ($("#volunteerProfileMessage")) {
      $("#volunteerProfileMessage").textContent = willBlacklist ? "Access blacklisted." : "Access restored.";
    }
  } catch (err) {
    if ($("#volunteerProfileMessage")) $("#volunteerProfileMessage").textContent = err.message;
    showDialog([err.message]);
  }
}

function vendorHallAssignmentMap() {
  const map = {};
  vendorHallAssignments.forEach(assignment => {
    if (assignment && assignment.spotCode) map[assignment.spotCode] = assignment;
  });
  return map;
}

function renderVendorHall() {
  const grid = $("#vendorHallMap");
  if (!grid || !currentUser?.canVendorHall) return;
  const assignments = vendorHallAssignmentMap();
  const occupied = Object.keys(assignments).length;
  if ($("#vendorHallSummary")) {
    $("#vendorHallSummary").textContent = `${occupied} occupied / ${VENDOR_HALL_SPOTS.length - occupied} available of ${VENDOR_HALL_SPOTS.length} positions`;
  }
  grid.innerHTML = VENDOR_HALL_SPOTS.map(spot => {
    const assignment = assignments[spot];
    const isOccupied = Boolean(assignment);
    const isSelected = selectedVendorHallSpot === spot;
    const stateLabel = isOccupied ? "Occupied" : "Available";
    const vendor = isOccupied ? escapeHtml(assignment.vendorName) : "Available";
    return `
      <button type="button" role="listitem"
        class="vendor-hall-spot ${isOccupied ? "is-occupied" : "is-available"} ${isSelected ? "is-selected" : ""}"
        data-vendor-spot="${escapeHtml(spot)}"
        aria-pressed="${isSelected ? "true" : "false"}"
        aria-label="Position ${escapeHtml(spot)}, ${stateLabel}${isOccupied ? `, ${vendor}` : ""}">
        <span class="vendor-hall-spot-code">${escapeHtml(spot)}</span>
        <span class="vendor-hall-spot-state">${isOccupied ? "●" : "○"} ${stateLabel}</span>
        <span class="vendor-hall-spot-vendor">${vendor}</span>
      </button>`;
  }).join("");
  renderVendorHallEditor();
}

function renderVendorHallEditor() {
  const form = $("#vendorHallForm");
  const title = $("#vendorHallEditorTitle");
  if (!form || !currentUser?.canVendorHall) return;
  if (!selectedVendorHallSpot) {
    form.hidden = true;
    if (title) title.textContent = "Select a position";
    const clearBtn = $("#vendorHallClearBtn");
    if (clearBtn) clearBtn.hidden = true;
    return;
  }
  const assignment = vendorHallAssignmentMap()[selectedVendorHallSpot] || null;
  form.hidden = false;
  if (title) title.textContent = `Position ${selectedVendorHallSpot}`;
  if ($("#vendorHallSpotCode")) $("#vendorHallSpotCode").value = selectedVendorHallSpot;
  if ($("#vendorHallVendorName")) $("#vendorHallVendorName").value = assignment ? assignment.vendorName || "" : "";
  if ($("#vendorHallNotes")) $("#vendorHallNotes").value = assignment ? assignment.notes || "" : "";
  const clearBtn = $("#vendorHallClearBtn");
  if (clearBtn) clearBtn.hidden = !assignment;
  if ($("#vendorHallUpdatedMeta")) {
    $("#vendorHallUpdatedMeta").textContent = assignment && assignment.updatedAt
      ? `Last updated ${formatIncidentDate(assignment.updatedAt)}${assignment.updatedByName ? ` by ${assignment.updatedByName}` : ""}.`
      : "No vendor assigned to this position yet.";
  }
}

function selectVendorHallSpot(spot) {
  if (!VENDOR_HALL_SPOTS.includes(spot)) return;
  selectedVendorHallSpot = spot;
  renderVendorHall();
  const nameInput = $("#vendorHallVendorName");
  if (nameInput) nameInput.focus();
}

async function saveVendorHallAssignment(event) {
  event.preventDefault();
  const message = $("#vendorHallMessage");
  const payload = {
    spotCode: $("#vendorHallSpotCode")?.value || selectedVendorHallSpot || "",
    vendorName: $("#vendorHallVendorName")?.value || "",
    notes: $("#vendorHallNotes")?.value || ""
  };
  try {
    const data = await apiRequest("save_vendor_hall_assignment", payload);
    applyState(data);
    if (message) message.textContent = `Saved position ${payload.spotCode}.`;
  } catch (err) {
    if (message) message.textContent = err.message;
    showDialog([err.message]);
  }
}

async function clearVendorHallAssignment() {
  const spot = $("#vendorHallSpotCode")?.value || selectedVendorHallSpot || "";
  if (!spot) return;
  if (!confirm(`Clear the assignment for position ${spot}?`)) return;
  const message = $("#vendorHallMessage");
  try {
    const data = await apiRequest("clear_vendor_hall_assignment", { spotCode: spot });
    applyState(data);
    if (message) message.textContent = `Cleared position ${spot}.`;
  } catch (err) {
    if (message) message.textContent = err.message;
    showDialog([err.message]);
  }
}

function applicationDetailsHtml(user) {
  const details = [
    ["Discord", user.discord || user.discord_username || user.discordUsername || ""],
    ["DOB", user.dateOfBirth || user.date_of_birth || ""],
    ["Gender", user.gender || ""],
    ["T-shirt", user.shirtSize || user.shirt_size || ""],
    ["Worked before", user.previousExperience || user.previous_experience || ""],
    ["Skills", user.skills || ""],
    ["Allergies", allergyText(user) || ""],
    ["Notes / friends", user.additionalNotes || user.additional_notes || user.buddyRequest || ""]
  ].filter(([, value]) => String(value || "").trim() !== "");

  return details.length
    ? `<div class="application-answer-list">${details.map(([label, value]) => `<div><strong>${escapeHtml(label)}:</strong> ${escapeHtml(value)}</div>`).join("")}</div>`
    : `<span class="summary">No extra details yet.</span>`;
}

function renderShiftManagement() {
  if (!isManagementUser(currentUser)) return;
  renderManagementDepartmentFilter();

  const shiftSelect = $("#manageShiftSelect");
  const volunteerSelect = $("#manageVolunteerSelect");
  if (!shiftSelect || !volunteerSelect) return;

  const previousShift = shiftSelect.value;
  const previousVolunteer = volunteerSelect.value;
  const visibleShifts = sortShifts(departmentFilteredManagerShifts(), "department");
  const visibleVolunteers = approvedVolunteers();
  shiftSelect.innerHTML = visibleShifts.map(shift => `<option value="${escapeHtml(shift.id)}">${escapeHtml(shiftLabel(shift))}</option>`).join("");
  volunteerSelect.innerHTML = visibleVolunteers.map(user => `<option value="${escapeHtml(user.id)}">${escapeHtml(user.name)} - ${escapeHtml(userDepartment(user) || "Unassigned")}</option>`).join("");

  if (focusedManagedShiftId && visibleShifts.some(shift => String(shift.id) === String(focusedManagedShiftId))) {
    shiftSelect.value = focusedManagedShiftId;
    focusedManagedShiftId = null;
  } else if (visibleShifts.some(shift => String(shift.id) === String(previousShift))) {
    shiftSelect.value = previousShift;
  }
  if (visibleVolunteers.some(user => String(user.id) === String(previousVolunteer))) volunteerSelect.value = previousVolunteer;

  const selectedShift = selectedManagedShift();
  const recommendedList = $("#shiftRecommendedList");
  const assignedList = $("#shiftAssignedList");
  if (!selectedShift || !recommendedList || !assignedList) {
    if (recommendedList) recommendedList.innerHTML = `<p class="summary">No shifts available for your department yet.</p>`;
    if (assignedList) assignedList.innerHTML = `<p class="summary">Create a shift before assigning volunteers.</p>`;
    return;
  }

  const assigned = managerVisibleUsers().filter(user => userHasShift(user, selectedShift.id));
  const recommended = recommendedUsersForShift(selectedShift);

  recommendedList.innerHTML = recommended.length ? recommended.map(user => `
    <article class="admin-list-card">
      <div>
        <strong>${escapeHtml(user.name)}</strong>
        <span>${escapeHtml(userDepartment(user) || "Unassigned")}</span>
      </div>
      <span>${escapeHtml(availabilitySummaryForShift(user, selectedShift))}${preferenceSummary(user) ? `<br>${escapeHtml(preferenceSummary(user))}` : ""}</span>
      <button class="quiet-button" type="button" onclick="assignSpecificVolunteer('${escapeJs(user.id)}', '${escapeJs(selectedShift.id)}')">Assign</button>
    </article>
  `).join("") : `<p class="summary">${escapeHtml(recommendationEmptyReason(selectedShift))}</p>`;

  assignedList.innerHTML = assigned.length ? assigned.map(user => `
    <article class="admin-list-card">
      <div>
        <strong>${escapeHtml(user.name)}</strong>
        <span>${escapeHtml(user.email)}</span>
      </div>
      <span>${escapeHtml(userDepartment(user) || "Unassigned")}${preferenceSummary(user) ? `<br>${escapeHtml(preferenceSummary(user))}` : ""}</span>
      <button class="quiet-button" type="button" onclick="revokeSpecificVolunteer('${escapeJs(user.id)}', '${escapeJs(selectedShift.id)}')">Revoke</button>
    </article>
  `).join("") : `<p class="summary">No one is assigned to this shift yet.</p>`;
}

function renderHotels() {
  const list = $("#hotelRoomList");
  if (!list || !isManagementUser(currentUser)) return;

  const filter = $("#hotelGenderFilter")?.value || "All";
  const hotelUsers = managerVisibleUsers()
    .filter(user => (user.hotelNeeded || user.hotel_needed || "No") === "Yes")
    .filter(user => filter === "All" || hotelGenderLabel(user) === filter)
    .sort((a, b) => `${hotelGenderLabel(a)} ${a.name}`.localeCompare(`${hotelGenderLabel(b)} ${b.name}`));

  const rooms = normalizedHotelRooms();
  const roomCards = [
    {
      id: "",
      room_name: "Unassigned",
      capacity: hotelUsers.filter(user => !user.hotelRoom).length || 4,
      gender: "",
      occupants: hotelUsers.filter(user => !user.hotelRoom)
    },
    ...rooms.map(room => ({
      ...room,
      occupants: hotelUsers.filter(user => String(user.hotelRoom) === String(room.room_name))
    }))
  ];

  list.innerHTML = roomCards.map(room => hotelRoomCardHtml(room, rooms)).join("") || `<p class="summary">No hotel requests match this filter yet.</p>`;
}

function renderDiscordDmTools() {
  const select = $("#discordDmVolunteerSelect");
  if (!select || !isManagementUser(currentUser)) return;
  const current = select.value;
  const volunteers = managerVisibleUsers()
    .filter(user => user.status === "approved")
    .sort((a, b) => a.name.localeCompare(b.name));
  select.innerHTML = volunteers.map(user => {
    const linked = user.discord_id || user.discordId;
    const suffix = linked ? "Discord linked" : "No Discord link";
    return `<option value="${escapeHtml(user.id)}">${escapeHtml(user.name)} - ${escapeHtml(suffix)}</option>`;
  }).join("");
  if (volunteers.some(user => String(user.id) === String(current))) select.value = current;
}

function renderSystemLogs() {
  const list = $("#systemLogList");
  if (!list || !isManagementUser(currentUser)) return;
  list.innerHTML = systemLogs.length ? systemLogs.slice(0, 40).map(log => `
    <article class="admin-list-card">
      <div>
        <strong>${escapeHtml(log.action || "activity")}</strong>
        <span>${escapeHtml(log.created_at || "")}</span>
      </div>
      <span>${escapeHtml(log.details || "")}</span>
      <span class="badge">${escapeHtml(log.actor_name || "System")}</span>
    </article>
  `).join("") : `<p class="summary">No logged activity yet.</p>`;
}

async function sendDiscordDmToVolunteer() {
  const userId = $("#discordDmVolunteerSelect")?.value || "";
  const messageInput = $("#discordDmMessage");
  const status = $("#discordDmStatus");
  const message = (messageInput?.value || "").trim();
  const user = users.find(item => String(item.id) === String(userId));
  if (!userId || !message) {
    if (status) status.textContent = "Choose a volunteer and enter a message.";
    return;
  }
  if (!confirm(`Send Discord DM to ${user?.name || "this volunteer"}?`)) return;
  try {
    const data = await apiRequest("send_discord_dm", { userId, message });
    applyState(data);
    if (messageInput) messageInput.value = "";
    if (status) status.textContent = "Discord DM sent.";
    showDialog(["Discord DM sent."]);
  } catch(err) {
    if (status) status.textContent = err.message;
    showDialog([err.message]);
  }
}

function renderGuestFlights() {
  const list = $("#guestFlightList");
  if (!list || !currentUser?.canGuestRelations) return;
  const assignee = $("#guestPickupAssignee");
  if (assignee) {
    const current = assignee.value;
    assignee.innerHTML = `<option value="">Choose active Guest Relations staff</option>` + pickupStaff.map(person =>
      `<option value="${escapeHtml(person.id)}">${escapeHtml(person.name)}${person.department ? ` - ${escapeHtml(person.department)}` : ""}</option>`
    ).join("");
    if (pickupStaff.some(person => String(person.id) === String(current))) assignee.value = current;
  }
  list.innerHTML = guestFlights.length ? guestFlights.map(flight => `
    <article class="admin-list-card">
      <div>
        <strong>${escapeHtml(flight.guest_name || flight.guestName || "")}</strong>
        <span>${escapeHtml(flight.flight_number || "")} - ${escapeHtml(flight.flight_date || "")}</span>
      </div>
      <span>${escapeHtml([flight.airline, flight.departure_airport, flight.arrival_airport].filter(Boolean).join(" -> ") || "Route pending")}</span>
      <span>Confirmation: ${escapeHtml(flight.confirmation_number ? `••••${String(flight.confirmation_number).slice(-2)}` : "Not saved")}</span>
      <label>Pickup person
        <select data-flight-assignee="${escapeHtml(flight.id)}">
          ${pickupStaff.map(person => `<option value="${escapeHtml(person.id)}" ${String(person.id) === String(flight.assigned_user_id) ? "selected" : ""}>${escapeHtml(person.name)}</option>`).join("")}
        </select>
      </label>
      <span class="badge">${escapeHtml(flight.flight_status || "Saved")}</span>
      ${flight.notification_error ? `<span class="message">Discord update failed: ${escapeHtml(flight.notification_error)}</span>` : ""}
    </article>
  `).join("") : `<p class="summary">No guest flights tracked yet.</p>`;
}

async function saveGuestFlight(event) {
  event.preventDefault();
  const message = $("#guestFlightMessage");
  const payload = {
    guestName: $("#guestFlightName")?.value || "",
    confirmationNumber: $("#guestConfirmationNumber")?.value || "",
    flightNumber: $("#guestFlightNumber")?.value || "",
    flightDate: $("#guestFlightDate")?.value || "",
    assignedUserId: $("#guestPickupAssignee")?.value || ""
  };
  try {
    const data = await apiRequest("save_guest_flight", payload);
    applyState(data);
    if ($("#guestFlightForm")) $("#guestFlightForm").reset();
    if (message) message.textContent = "Guest flight saved.";
    showDialog(["Guest flight saved."]);
  } catch(err) {
    if (message) message.textContent = err.message;
    showDialog([err.message]);
  }
}

async function refreshGuestFlights() {
  const message = $("#guestFlightMessage");
  const button = $("#refreshGuestFlightsBtn");
  if (button) button.disabled = true;
  try {
    const data = await apiRequest("refresh_guest_flights", {});
    const result = data.flightRefreshResult || {};
    applyState(data);
    const text = `Refreshed ${result.updated || 0} flights and sent ${result.notified || 0} pickup updates.`;
    if (message) message.textContent = text;
    showDialog([text]);
  } catch (err) {
    if (message) message.textContent = err.message;
    showDialog([err.message]);
  } finally {
    if (button) button.disabled = false;
  }
}

async function updateGuestFlightAssignee(select) {
  const flightId = select.dataset.flightAssignee || "";
  const assignedUserId = select.value || "";
  select.disabled = true;
  try {
    const data = await apiRequest("update_guest_flight_assignee", { flightId, assignedUserId });
    applyState(data);
    showDialog(["Pickup assignment updated and the assigned person was notified."]);
  } catch (err) {
    showDialog([err.message]);
    renderGuestFlights();
  } finally {
    select.disabled = false;
  }
}

function selectedIncident() {
  return incidents.find(incident => String(incident.id) === String(selectedIncidentId)) || null;
}

function renderSafety() {
  const view = $("#safetyView");
  if (!view || !currentUser?.canSafety) return;
  renderSafetyStats();
  renderIncidentList();
  renderIncidentEditor();
}

function renderSafetyStats() {
  const stats = $("#safetyStats");
  if (!stats) return;
  const active = incidents.filter(incident => !["Resolved", "Closed"].includes(incident.status)).length;
  const urgent = incidents.filter(incident => ["High", "Critical"].includes(incident.severity) && !["Resolved", "Closed"].includes(incident.status)).length;
  const evidenceCount = incidents.reduce((total, incident) => total + (incident.evidence || []).length, 0);
  const resolved = incidents.filter(incident => ["Resolved", "Closed"].includes(incident.status)).length;
  stats.innerHTML = `
    <article class="stat safety-stat active"><span>Active incidents</span><strong>${active}</strong><small>Open, investigating, or monitoring</small></article>
    <article class="stat safety-stat urgent"><span>High priority</span><strong>${urgent}</strong><small>High or critical and still active</small></article>
    <article class="stat safety-stat"><span>Evidence items</span><strong>${evidenceCount}</strong><small>Protected photos, files, and links</small></article>
    <article class="stat safety-stat resolved"><span>Resolved</span><strong>${resolved}</strong><small>Resolved or closed reports</small></article>
  `;
}

function renderIncidentList() {
  const list = $("#incidentList");
  if (!list || !currentUser?.canSafety) return;
  const query = ($("#incidentSearch")?.value || "").trim().toLowerCase();
  const status = $("#incidentStatusFilter")?.value || "All";
  const severity = $("#incidentSeverityFilter")?.value || "All";
  const visible = incidents.filter(incident => {
    const haystack = [incident.incident_number, incident.title, incident.location, incident.description].join(" ").toLowerCase();
    return (!query || haystack.includes(query))
      && (status === "All" || incident.status === status)
      && (severity === "All" || incident.severity === severity);
  });

  if ($("#incidentResultCount")) {
    $("#incidentResultCount").textContent = `${visible.length} report${visible.length === 1 ? "" : "s"}`;
  }
  list.innerHTML = visible.length ? visible.map(incident => `
    <button class="incident-list-card ${String(incident.id) === String(selectedIncidentId) ? "is-selected" : ""}" type="button" data-incident-id="${escapeHtml(incident.id)}">
      <span class="incident-card-topline">
        <span class="incident-number">${escapeHtml(incident.incident_number || "Draft")}</span>
        <span class="severity-dot severity-${escapeHtml(String(incident.severity || "low").toLowerCase())}">${escapeHtml(incident.severity)}</span>
      </span>
      <strong>${escapeHtml(incident.title)}</strong>
      <span>${escapeHtml(incident.location || "Location not recorded")}</span>
      <span class="incident-card-meta">
        <time>${escapeHtml(formatIncidentDate(incident.occurred_at))}</time>
        <span class="status-pill status-${escapeHtml(String(incident.status || "open").toLowerCase())}">${escapeHtml(incident.status)}</span>
        <span>${(incident.evidence || []).length} evidence</span>
      </span>
    </button>
  `).join("") : `<div class="empty-state"><span>✓</span><strong>No matching incidents</strong><p>Adjust the filters or create a new report.</p></div>`;

  $$('[data-incident-id]').forEach(button => {
    button.addEventListener("click", () => {
      selectedIncidentId = String(button.dataset.incidentId);
      renderSafety();
      $("#incidentEditorTitle")?.scrollIntoView({ behavior: "smooth", block: "start" });
    });
  });
}

function renderIncidentEditor() {
  const form = $("#incidentForm");
  if (!form || !currentUser?.canSafety) return;
  const incident = selectedIncident();

  setValue("#incidentId", incident?.id || "");
  setValue("#incidentTitle", incident?.title || "");
  setValue("#incidentType", incident?.incident_type || "Other");
  setValue("#incidentSeverity", incident?.severity || "Low");
  setValue("#incidentStatus", incident?.status || "Open");
  setValue("#incidentOccurredAt", incident ? mysqlDateTimeToInput(incident.occurred_at) : localDateTimeInput(new Date()));
  setValue("#incidentLocation", incident?.location || "");
  setValue("#incidentDescription", incident?.description || "");
  setValue("#incidentActionsTaken", incident?.actions_taken || "");
  setValue("#incidentPeopleInvolved", incident?.people_involved || "");
  setValue("#incidentWitnesses", incident?.witnesses || "");
  if ($("#incidentMedicalAttention")) $("#incidentMedicalAttention").checked = !!incident?.medical_attention;
  if ($("#incidentPoliceContacted")) $("#incidentPoliceContacted").checked = !!incident?.police_contacted;
  if ($("#incidentEditorTitle")) $("#incidentEditorTitle").textContent = incident ? incident.title : "Create a new report";
  if ($("#incidentNumberBadge")) $("#incidentNumberBadge").textContent = incident?.incident_number || "Draft";
  if ($("#incidentFormMessage")) $("#incidentFormMessage").textContent = "";

  const hasSavedIncident = !!incident;
  if ($("#evidenceControls")) $("#evidenceControls").hidden = !hasSavedIncident;
  if ($("#evidenceEmptyHint")) $("#evidenceEmptyHint").hidden = hasSavedIncident;
  renderIncidentEvidence(incident);
  renderIncidentActivity(incident);
}

function renderIncidentEvidence(incident) {
  const list = $("#incidentEvidenceList");
  if (!list) return;
  const evidenceItems = incident?.evidence || [];
  list.innerHTML = evidenceItems.map(item => {
    const isUpload = item.evidence_type === "upload";
    const targetUrl = isUpload
      ? `${configuredApiBase}?action=incident_evidence&id=${encodeURIComponent(item.id)}`
      : String(item.source_url || "");
    const isImage = isUpload
      ? String(item.mime_type || "").startsWith("image/")
      : /\.(png|jpe?g|webp|gif)(?:[?#].*)?$/i.test(targetUrl) || /(^|\.)i\.imgur\.com$/i.test(safeUrlHost(targetUrl));
    return `
      <article class="evidence-card">
        <a class="evidence-preview" href="${escapeHtml(targetUrl)}" target="_blank" rel="noopener noreferrer">
          ${isImage
            ? `<img src="${escapeHtml(targetUrl)}" alt="${escapeHtml(item.caption || item.file_name || "Incident evidence")}" loading="lazy" referrerpolicy="no-referrer" onerror="this.closest('.evidence-preview').classList.add('preview-failed')" />`
            : `<span class="file-preview">${isUpload && item.mime_type === "application/pdf" ? "PDF" : "LINK"}</span>`}
        </a>
        <div class="evidence-card-body">
          <strong>${escapeHtml(item.caption || item.file_name || "Evidence attachment")}</strong>
          <span>${escapeHtml(isUpload ? item.file_name : safeUrlHost(targetUrl) || "External source")}</span>
          <small>Added by ${escapeHtml(item.uploaded_by_name || "Safety")} · ${escapeHtml(formatIncidentDate(item.created_at))}</small>
          <button class="evidence-remove" type="button" data-remove-evidence="${escapeHtml(item.id)}">Remove</button>
        </div>
      </article>
    `;
  }).join("") || (incident ? `<p class="summary">No evidence attached to this report yet.</p>` : "");

  $$('[data-remove-evidence]').forEach(button => {
    button.addEventListener("click", () => removeSafetyEvidence(button.dataset.removeEvidence));
  });
}

function renderIncidentActivity(incident) {
  const list = $("#incidentActivityList");
  if (!list) return;
  const activity = incident?.activity || [];
  list.innerHTML = activity.map(item => `
    <article class="timeline-item">
      <span class="timeline-marker"></span>
      <div>
        <strong>${escapeHtml(item.action)}</strong>
        <p>${escapeHtml(item.details || "")}</p>
        <small>${escapeHtml(item.actor_name || "Safety")} · ${escapeHtml(formatIncidentDate(item.created_at))}</small>
      </div>
    </article>
  `).join("") || `<p class="summary">Activity appears after the report is saved.</p>`;
}

function startNewIncident() {
  selectedIncidentId = null;
  renderSafety();
  if ($("#incidentFormMessage")) $("#incidentFormMessage").textContent = "New incident draft.";
  $("#incidentTitle")?.focus();
}

async function saveSafetyIncident(event) {
  event.preventDefault();
  const message = $("#incidentFormMessage");
  const payload = {
    id: $("#incidentId")?.value || "",
    title: $("#incidentTitle")?.value || "",
    incidentType: $("#incidentType")?.value || "Other",
    severity: $("#incidentSeverity")?.value || "Low",
    status: $("#incidentStatus")?.value || "Open",
    occurredAt: $("#incidentOccurredAt")?.value || "",
    location: $("#incidentLocation")?.value || "",
    description: $("#incidentDescription")?.value || "",
    actionsTaken: $("#incidentActionsTaken")?.value || "",
    peopleInvolved: $("#incidentPeopleInvolved")?.value || "",
    witnesses: $("#incidentWitnesses")?.value || "",
    medicalAttention: !!$("#incidentMedicalAttention")?.checked,
    policeContacted: !!$("#incidentPoliceContacted")?.checked
  };
  try {
    if (message) message.textContent = "Saving incident…";
    const data = await apiRequest("save_incident", payload);
    selectedIncidentId = String(data.savedIncidentId || payload.id);
    applyState(data);
    if (message) message.textContent = "Incident saved.";
    showDialog(["Incident saved to the Safety log."]);
  } catch (err) {
    if (message) message.textContent = err.message;
  }
}

async function uploadSafetyEvidence() {
  const incident = selectedIncident();
  const fileInput = $("#incidentEvidenceFile");
  const message = $("#incidentEvidenceMessage");
  const file = fileInput?.files?.[0];
  if (!incident || !file) {
    if (message) message.textContent = "Save the incident and choose a photo or PDF first.";
    return;
  }
  const formData = new FormData();
  formData.append("incidentId", String(incident.id));
  formData.append("caption", $("#incidentEvidenceCaption")?.value || "");
  formData.append("evidence", file);
  try {
    if (message) message.textContent = "Uploading protected evidence…";
    const data = await apiUpload("upload_incident_evidence", formData);
    applyState(data);
    if (fileInput) fileInput.value = "";
    if ($("#incidentEvidenceCaption")) $("#incidentEvidenceCaption").value = "";
    if (message) message.textContent = "Evidence uploaded.";
  } catch (err) {
    if (message) message.textContent = err.message;
  }
}

async function attachSafetyEvidenceUrl() {
  const incident = selectedIncident();
  const urlInput = $("#incidentEvidenceUrl");
  const message = $("#incidentEvidenceMessage");
  const url = (urlInput?.value || "").trim();
  if (!incident || !url) {
    if (message) message.textContent = "Save the incident and enter an HTTPS evidence link first.";
    return;
  }
  try {
    const data = await apiRequest("add_incident_evidence_url", {
      incidentId: incident.id,
      url,
      caption: $("#incidentEvidenceUrlCaption")?.value || ""
    });
    applyState(data);
    if (urlInput) urlInput.value = "";
    if ($("#incidentEvidenceUrlCaption")) $("#incidentEvidenceUrlCaption").value = "";
    if (message) message.textContent = "Evidence link attached.";
  } catch (err) {
    if (message) message.textContent = err.message;
  }
}

async function removeSafetyEvidence(evidenceId) {
  if (!confirm("Remove this evidence attachment from the incident?")) return;
  const message = $("#incidentEvidenceMessage");
  try {
    const data = await apiRequest("remove_incident_evidence", { evidenceId });
    applyState(data);
    if (message) message.textContent = "Evidence removed and noted in the audit trail.";
  } catch (err) {
    if (message) message.textContent = err.message;
  }
}

function renderShiftAlerts() {
  const form = $("#shiftAlertRuleForm");
  if (!form || !isManagementUser(currentUser)) return;
  const select = $("#alertShiftSelect");
  const visibleShifts = sortShifts(managerVisibleShifts(), "department");
  const previous = select.value;
  select.innerHTML = visibleShifts.map(shift => `<option value="${escapeHtml(shift.id)}">${escapeHtml(shiftLabel(shift))}</option>`).join("");
  if (visibleShifts.some(shift => String(shift.id) === String(previous))) select.value = previous;
  renderSelectedAlertRule();
  renderAlertRuleList();
  renderAlertDeliveries();
}

function renderSelectedAlertRule() {
  const shiftId = $("#alertShiftSelect")?.value || "";
  const rule = alertRules.find(item => String(item.shift_id) === String(shiftId));
  setValue("#alertShiftDate", rule?.shift_date || "");
  setValue("#alertGraceMinutes", rule?.grace_minutes || "5");
  setValue("#alertMessageTemplate", rule?.message_template || "");
  if ($("#alertRuleEnabled")) $("#alertRuleEnabled").checked = rule ? !!rule.enabled : true;

  const selectedRecipients = new Set((rule?.recipientUserIds || []).map(String));
  const candidates = managerVisibleUsers()
    .filter(user => user.status === "approved")
    .filter(user => user.discord_id || user.discordId)
    .sort((a, b) => `${recipientPriority(a)} ${a.name}`.localeCompare(`${recipientPriority(b)} ${b.name}`));
  const list = $("#alertRecipientList");
  if (!list) return;
  list.innerHTML = candidates.length ? candidates.map(user => `
    <label class="recipient-card ${selectedRecipients.has(String(user.id)) ? "is-selected" : ""}">
      <input type="checkbox" value="${escapeHtml(user.id)}" data-alert-recipient ${selectedRecipients.has(String(user.id)) ? "checked" : ""} />
      <span class="recipient-avatar">${escapeHtml(initials(user.name))}</span>
      <span><strong>${escapeHtml(user.name)}</strong><small>${escapeHtml(user.rank || "Volunteer")} · ${escapeHtml(userDepartment(user) || "Unassigned")}</small></span>
      <span class="discord-linked">Linked</span>
    </label>
  `).join("") : `<p class="summary">No approved users in this scope have linked Discord yet.</p>`;
  $$('[data-alert-recipient]').forEach(input => {
    input.addEventListener("change", () => input.closest(".recipient-card")?.classList.toggle("is-selected", input.checked));
  });
}

function renderAlertRuleList() {
  const list = $("#alertRuleList");
  if (!list) return;
  list.innerHTML = alertRules.length ? alertRules.map(rule => {
    const names = (rule.recipientUserIds || []).map(id => users.find(user => String(user.id) === String(id))?.name).filter(Boolean);
    return `
      <button class="alert-rule-card" type="button" data-alert-rule-shift="${escapeHtml(rule.shift_id)}">
        <span class="alert-rule-state ${rule.enabled ? "is-on" : "is-off"}">${rule.enabled ? "Active" : "Paused"}</span>
        <strong>${escapeHtml(rule.shift_title || "Shift")}</strong>
        <span>${escapeHtml(rule.shift_date)} · ${escapeHtml(rule.shift_time)} · ${escapeHtml(rule.grace_minutes)} min grace</span>
        <small>DM: ${escapeHtml(names.join(", ") || "No visible recipients")}</small>
      </button>
    `;
  }).join("") : `<p class="summary">No missed check-in alert rules yet.</p>`;
  $$('[data-alert-rule-shift]').forEach(button => {
    button.addEventListener("click", () => {
      if ($("#alertShiftSelect")) $("#alertShiftSelect").value = button.dataset.alertRuleShift;
      renderSelectedAlertRule();
      $("#shiftAlertTitle")?.scrollIntoView({ behavior: "smooth", block: "start" });
    });
  });
}

function renderAlertDeliveries() {
  const list = $("#alertDeliveryList");
  if (!list) return;
  list.innerHTML = alertDeliveries.length ? alertDeliveries.slice(0, 12).map(delivery => `
    <article class="alert-delivery-card status-${escapeHtml(delivery.status || "pending")}">
      <span class="delivery-status">${escapeHtml(delivery.status || "pending")}</span>
      <div><strong>${escapeHtml(delivery.volunteer_name || "Volunteer")}</strong><span>${escapeHtml(delivery.shift_title || "Shift")}</span></div>
      <small>To ${escapeHtml(delivery.recipient_name || "recipient")} · ${escapeHtml(formatIncidentDate(delivery.updated_at))}</small>
      ${delivery.last_error ? `<p>${escapeHtml(delivery.last_error)}</p>` : ""}
    </article>
  `).join("") : `<p class="summary">No alert deliveries have been attempted yet.</p>`;
}

async function saveShiftAlertRule(event) {
  event.preventDefault();
  const message = $("#shiftAlertMessage");
  const recipientUserIds = $$('[data-alert-recipient]:checked').map(input => input.value);
  try {
    if (message) message.textContent = "Saving alert rule…";
    const data = await apiRequest("save_shift_alert_rule", {
      shiftId: $("#alertShiftSelect")?.value || "",
      shiftDate: $("#alertShiftDate")?.value || "",
      graceMinutes: Number($("#alertGraceMinutes")?.value || 5),
      enabled: !!$("#alertRuleEnabled")?.checked,
      recipientUserIds,
      messageTemplate: $("#alertMessageTemplate")?.value || ""
    });
    applyState(data);
    if (message) message.textContent = "Alert rule saved.";
    showDialog(["Missed check-in alert rule saved."]);
  } catch (err) {
    if (message) message.textContent = err.message;
  }
}

async function runShiftAlertScan() {
  const message = $("#shiftAlertMessage");
  try {
    if (message) message.textContent = "Checking active shift rules…";
    const data = await apiRequest("process_shift_alerts", {});
    const result = data.alertProcessResult || {};
    applyState(data);
    const summary = `${result.rulesDue || 0} due rule${result.rulesDue === 1 ? "" : "s"}, ${result.missingVolunteers || 0} missing volunteer${result.missingVolunteers === 1 ? "" : "s"}, ${result.sent || 0} DM${result.sent === 1 ? "" : "s"} sent.`;
    if (message) message.textContent = summary;
    showDialog([summary]);
  } catch (err) {
    if (message) message.textContent = err.message;
  }
}

function recipientPriority(user) {
  if (user.rank === "Admin") return "0";
  if (user.rank === "Coordinator") return "1";
  if (user.rank === "Staff") return "2";
  return "3";
}

function initials(name = "") {
  return String(name).split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0]).join("").toUpperCase() || "DH";
}

function safeUrlHost(value) {
  try { return new URL(value).hostname; } catch { return ""; }
}

function localDateTimeInput(date) {
  const pad = value => String(value).padStart(2, "0");
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function mysqlDateTimeToInput(value) {
  const text = String(value || "").trim();
  return text ? text.replace(" ", "T").slice(0, 16) : localDateTimeInput(new Date());
}

function formatIncidentDate(value) {
  const text = String(value || "").trim();
  if (!text) return "";
  const parsed = new Date(text.includes("T") ? text : text.replace(" ", "T"));
  if (Number.isNaN(parsed.getTime())) return text;
  return new Intl.DateTimeFormat(undefined, { month: "short", day: "numeric", hour: "numeric", minute: "2-digit" }).format(parsed);
}

function normalizedHotelRooms() {
  const rooms = [...hotelRooms];
  managerVisibleUsers()
    .filter(user => user.hotelRoom)
    .forEach(user => {
      if (!rooms.some(room => String(room.room_name) === String(user.hotelRoom))) {
        rooms.push({ id: `legacy-${user.hotelRoom}`, room_name: user.hotelRoom, capacity: 4, gender: "" });
      }
    });
  return rooms.sort((a, b) => String(a.room_name).localeCompare(String(b.room_name)));
}

function hotelRoomCardHtml(room, rooms) {
  const capacity = Number(room.capacity || 4);
  const overCapacity = room.occupants.length > capacity;
  const genderLabel = room.gender || "Any";
  return `
    <article class="hotel-room-card ${overCapacity ? "over-capacity" : ""}">
      <div class="hotel-room-header">
        <div>
          <strong>${escapeHtml(room.room_name)}</strong>
          <div class="summary">${room.occupants.length}/${capacity} assigned - ${escapeHtml(genderLabel)}</div>
        </div>
        <span class="badge ${overCapacity ? "denied" : "approved"}">${overCapacity ? "Over capacity" : "Open"}</span>
      </div>
      <div class="admin-card-list">
        ${room.occupants.length ? room.occupants.map(user => hotelOccupantHtml(user, rooms)).join("") : `<p class="summary">No volunteers in this room.</p>`}
      </div>
    </article>
  `;
}

function hotelOccupantHtml(user, rooms) {
  const roomOptions = [
    `<option value="" ${!user.hotelRoom ? "selected" : ""}>Unassigned</option>`,
    ...rooms.map(room => `<option value="${escapeHtml(room.room_name)}" ${String(user.hotelRoom) === String(room.room_name) ? "selected" : ""}>${escapeHtml(room.room_name)}</option>`)
  ].join("");
  const genderOptions = ["", "Female", "Male", "Other", "Prefer not to say"].map(gender => {
    const label = gender || "Unspecified";
    return `<option value="${escapeHtml(gender)}" ${String(user.gender || "") === gender ? "selected" : ""}>${escapeHtml(label)}</option>`;
  }).join("");

  return `
    <div class="hotel-occupant">
      <div>
        <strong>${escapeHtml(user.name)}</strong>
        <div class="summary">${escapeHtml(hotelGenderLabel(user))} - ${escapeHtml(user.email || "")}</div>
      </div>
      <div class="hotel-occupant-actions">
        <select onchange="updateUserField(${user.id}, 'gender', this.value)">${genderOptions}</select>
        <select onchange="updateUserField(${user.id}, 'hotel_room', this.value)">${roomOptions}</select>
        <label class="checkbox-label" style="margin:0;">
          <input type="checkbox" ${user.hotelCheckedIn ? "checked" : ""} onchange="updateUserField(${user.id}, 'hotel_checked_in', this.checked ? '1' : '0')">
          Checked in
        </label>
      </div>
    </div>
  `;
}

function hotelGenderLabel(user) {
  return user.gender || user.hotel_gender || "Unspecified";
}

async function createHotelRoom() {
  const name = ($("#hotelRoomName")?.value || "").trim();
  const capacity = Number($("#hotelRoomCapacity")?.value || 4);
  const gender = $("#hotelRoomGender")?.value || "";
  const message = $("#hotelMessage");

  if (!name) {
    if (message) message.textContent = "Enter a room name.";
    return;
  }
  if (!Number.isFinite(capacity) || capacity <= 0) {
    if (message) message.textContent = "Room capacity must be at least 1.";
    return;
  }
  if (!confirm(`Add hotel room ${name}?`)) return;

  try {
    const data = await apiRequest("create_hotel_room", { roomName: name, capacity, gender });
    applyState(data);
    if ($("#hotelRoomName")) $("#hotelRoomName").value = "";
    if ($("#hotelRoomCapacity")) $("#hotelRoomCapacity").value = 4;
    if ($("#hotelRoomGender")) $("#hotelRoomGender").value = "";
    if (message) message.textContent = "Hotel room added.";
    showDialog(["Hotel room added."]);
  } catch(err) {
    if (message) message.textContent = err.message;
  }
}

function renderAdmin() {
  const visibleUsers = managerVisibleUsers();
  const visibleShifts = managerVisibleShifts();
  if ($("#totalVolunteers")) {
    $("#totalVolunteers").textContent = visibleUsers.filter(u => u.status === 'approved').length;
  }
  if ($("#totalHours")) {
    $("#totalHours").textContent = visibleUsers.reduce((total, user) => {
      return total + visibleShifts
        .filter(shift => userHasShift(user, shift.id))
        .reduce((sum, shift) => sum + Number(shift.hours || 0), 0);
    }, 0);
  }
  if ($("#hotelRequests")) {
    $("#hotelRequests").textContent = visibleUsers.filter(u => (u.hotel_needed || u.hotelNeeded) === "Yes").length;
  }
  if ($("#clockedInCount")) {
    $("#clockedInCount").textContent = visibleUsers.filter(u => u.clockedIn).length;
  }

  const clockedInList = $("#clockedInList");
  if (clockedInList) {
    clockedInList.innerHTML = visibleUsers.filter(u => u.clockedIn).map(u => `
      <div style="padding: 8px; border-bottom: 1px solid var(--border);">
        <strong>${escapeHtml(u.name)}</strong> - <small>${escapeHtml(u.department || 'Unassigned')}</small>
      </div>
    `).join("") || "<p style='padding:12px;'>No one is currently clocked in.</p>";
  }
}

function renderAdminAvailability() {
  const list = $("#adminAvailabilityList");
  if (!list || !isManagementUser(currentUser)) return;

  const hourFilter = $("#availabilityHourFilter")?.value || "All hours";
  const hours = hourFilter === "All hours" ? AVAILABILITY_HOURS : [hourFilter];
  list.innerHTML = hours.map(hour => {
    const available = managerVisibleUsers().filter(user => {
      const availability = normalizeAvailability(user.availability);
      return user.status === "approved" && availability[selectedAvailabilityDay].includes(hour);
    });
    return `
      <article class="admin-list-card">
        <div>
          <strong>${escapeHtml(selectedAvailabilityDay)} at ${escapeHtml(hour)}</strong>
          <span>${available.length} available</span>
        </div>
        <span>${available.length ? available.map(u => escapeHtml(u.name)).join(", ") : "No one available"}</span>
      </article>
    `;
  }).join("");
}

function renderRecommendedSchedule() {
  const list = $("#recommendedScheduleList");
  if (!list || !isManagementUser(currentUser)) return;
  list.innerHTML = latestRecommendations.length ? latestRecommendations.map(item => `
    <article class="admin-list-card">
      <div>
        <strong>${escapeHtml(item.shift.title)}</strong>
        <span>${escapeHtml(item.shift.day || item.shift.shift_day)}, ${escapeHtml(item.shift.time || item.shift.shift_time)}</span>
      </div>
      <span>${escapeHtml(item.volunteer.name)} - ${escapeHtml(item.reason)}</span>
      <span class="badge">${escapeHtml(item.shift.department)}</span>
    </article>
  `).join("") : `<p class="summary">Click Recommend to generate schedule suggestions.</p>`;
}

function renderOnShiftList() {
  const list = $("#onShiftList");
  if (!list || !isManagementUser(currentUser)) return;
  let currentGroup = "";
  list.innerHTML = sortShifts(departmentFilteredManagerShifts(), "department").map(shift => {
    const group = shift.department || "Unassigned";
    const divider = group !== currentGroup
      ? `<div class="shift-day-divider">${escapeHtml(group)}</div>`
      : "";
    currentGroup = group;
    const assigned = managerVisibleUsers().filter(user => (user.shiftIds || []).map(String).includes(String(shift.id)));
    return `
      ${divider}
      <article class="shift-card">
        <div>
          <p class="eyebrow">${escapeHtml(shift.department)}</p>
          <h3>${escapeHtml(shift.title)}</h3>
        </div>
        <div class="shift-meta">
          <span>${escapeHtml(shift.day || shift.shift_day)}, ${escapeHtml(shift.time || shift.shift_time)}</span>
          <span>${assigned.length}/${escapeHtml(shift.capacity)} assigned</span>
          <span>${assigned.length ? assigned.map(user => escapeHtml(user.name)).join(", ") : "No one assigned"}</span>
        </div>
      </article>
    `;
  }).join("") || `<p class="summary">No shifts have been created yet.</p>`;
}

function buildScheduleRecommendations() {
  const recommendations = [];
  const assignedVolunteerIds = new Set();

  managerVisibleShifts().forEach(shift => {
    const alreadyAssigned = managerVisibleUsers().filter(user => (user.shiftIds || []).map(String).includes(String(shift.id)));
    const openSlots = Math.max(Number(shift.capacity || 0) - alreadyAssigned.length, 0);
    if (openSlots <= 0) return;

    const candidates = approvedVolunteers()
      .filter(user => user.status === "approved")
      .filter(user => !assignedVolunteerIds.has(String(user.id)))
      .filter(user => !(user.shiftIds || []).map(String).includes(String(shift.id)))
      .filter(user => userDepartment(user) === shift.department)
      .filter(user => shiftMatchesAvailability(user, shift))
      .sort((a, b) => recommendationScore(a, shift) - recommendationScore(b, shift));

    candidates.slice(0, openSlots).forEach(user => {
      assignedVolunteerIds.add(String(user.id));
      recommendations.push({
        shift,
        volunteer: user,
        reason: "department and availability match"
      });
    });
  });

  return recommendations;
}

function selectedManagedShift() {
  const visibleShifts = departmentFilteredManagerShifts();
  const id = $("#manageShiftSelect")?.value || visibleShifts[0]?.id;
  return visibleShifts.find(shift => String(shift.id) === String(id)) || visibleShifts[0] || null;
}

function approvedVolunteers() {
  return managerVisibleUsers().filter(user => user.status === "approved" && !user.blacklisted);
}

function userHasShift(user, shiftId) {
  return (user.shiftIds || []).map(String).includes(String(shiftId));
}

function shiftLabel(shift) {
  return `${shift.day || shift.shift_day} - ${shift.department} - ${shift.title} (${shift.time || shift.shift_time})`;
}

function sortShifts(items, mode = "day") {
  const dayOrder = new Map(SCHEDULE_DAYS.map((day, index) => [day, index]));
  return [...items].sort((a, b) => {
    if (mode === "department") {
      return `${a.department} ${dayOrder.get(a.day || a.shift_day) ?? 99} ${hourToNumber((a.time || a.shift_time || "").split(/\s*-\s*/)[0]) ?? 99} ${a.title}`
        .localeCompare(`${b.department} ${dayOrder.get(b.day || b.shift_day) ?? 99} ${hourToNumber((b.time || b.shift_time || "").split(/\s*-\s*/)[0]) ?? 99} ${b.title}`);
    }
    if (mode === "title") {
      return `${a.title} ${a.department} ${dayOrder.get(a.day || a.shift_day) ?? 99}`.localeCompare(`${b.title} ${b.department} ${dayOrder.get(b.day || b.shift_day) ?? 99}`);
    }
    return (dayOrder.get(a.day || a.shift_day) ?? 99) - (dayOrder.get(b.day || b.shift_day) ?? 99)
      || (hourToNumber((a.time || a.shift_time || "").split(/\s*-\s*/)[0]) ?? 99) - (hourToNumber((b.time || b.shift_time || "").split(/\s*-\s*/)[0]) ?? 99)
      || String(a.department || "").localeCompare(String(b.department || ""))
      || String(a.title || "").localeCompare(String(b.title || ""));
  });
}

function recommendedUsersForShift(shift) {
  return approvedVolunteers()
    .filter(user => !userHasShift(user, shift.id))
    .filter(user => userDepartment(user) === shift.department)
    .filter(user => shiftMatchesAvailability(user, shift))
    .sort((a, b) => recommendationScore(a, shift) - recommendationScore(b, shift));
}

function recommendationEmptyReason(shift) {
  const deptMatches = approvedVolunteers()
    .filter(user => !userHasShift(user, shift.id))
    .filter(user => userDepartment(user) === shift.department);

  if (!deptMatches.length) {
    return `No approved, unassigned volunteers are in ${shift.department}. Check the volunteer's Assigned Dept in Active Accounts.`;
  }

  const availableMatches = deptMatches.filter(user => shiftMatchesAvailability(user, shift));
  if (!availableMatches.length) {
    return `No ${shift.department} volunteers have availability overlapping ${shift.day || shift.shift_day} ${shift.time || shift.shift_time}.`;
  }

  return "All matching volunteers are already assigned to this shift.";
}

function availabilitySummaryForShift(user, shift) {
  const day = shift.day || shift.shift_day;
  const available = normalizeAvailability(user.availability)[day] || [];
  const matched = shiftHourSlots(shift).filter(hour => available.includes(hour));
  return matched.length ? `Available: ${matched.join(", ")}` : "No matching hours";
}

function preferenceSummary(user) {
  const buddy = user.buddyRequest || user.buddy_request || user.friend || "";
  const carpool = user.carpoolRequest || user.carpool_request || "";
  return [buddy ? `Buddy: ${buddy}` : "", carpool ? `Carpool: ${carpool}` : ""].filter(Boolean).join(" | ");
}

function recommendationScore(user, shift) {
  const day = shift.day || shift.shift_day;
  const buddyText = String(user.buddyRequest || user.buddy_request || user.friend || "").toLowerCase();
  const carpoolText = String(user.carpoolRequest || user.carpool_request || "").toLowerCase();
  const sameDayNames = managerVisibleUsers()
    .filter(other => String(other.id) !== String(user.id))
    .filter(other => userWorksDay(other, day))
    .map(other => String(other.name || "").toLowerCase());
  const hasCloseRequest = sameDayNames.some(name => name && (buddyText.includes(name) || carpoolText.includes(name)));
  return (user.shiftIds || []).length - (hasCloseRequest ? 2 : 0);
}

function userWorksDay(user, day) {
  return managerVisibleShifts().some(shift => (shift.day || shift.shift_day) === day && userHasShift(user, shift.id));
}

function dailyCoverageCounts(dayFilter = "All days") {
  return SCHEDULE_DAYS
    .filter(day => dayFilter === "All days" || day === dayFilter)
    .map(day => {
      const dayShifts = managerVisibleShifts().filter(shift => (shift.day || shift.shift_day) === day);
      const assignedIds = new Set();
      const mealIds = {
        breakfast: new Set(),
        lunch: new Set(),
        dinner: new Set()
      };
      dayShifts.forEach(shift => {
        managerVisibleUsers().forEach(user => {
          if (!userHasShift(user, shift.id)) return;
          assignedIds.add(String(user.id));
          Object.keys(mealIds).forEach(meal => {
            if (shiftOverlapsWindow(shift, mealWindows[meal])) {
              mealIds[meal].add(String(user.id));
            }
          });
        });
      });
      return {
        day,
        shiftCount: dayShifts.length,
        volunteerCount: assignedIds.size,
        foodCount: assignedIds.size,
        breakfastCount: mealIds.breakfast.size,
        lunchCount: mealIds.lunch.size,
        dinnerCount: mealIds.dinner.size
      };
    });
}

function renderDailyCounts() {
  const list = $("#dailyCountList");
  if (!list || !isManagementUser(currentUser)) return;
  list.innerHTML = dailyCoverageCounts().map(item => `
    <article class="admin-list-card count-card">
      <div>
        <strong>${escapeHtml(item.day)}</strong>
        <span>${item.shiftCount} shift${item.shiftCount === 1 ? "" : "s"} created</span>
      </div>
      <span>${item.volunteerCount} volunteer${item.volunteerCount === 1 ? "" : "s"} scheduled</span>
      <span class="badge approved">B ${item.breakfastCount} | L ${item.lunchCount} | D ${item.dinnerCount}</span>
    </article>
  `).join("") || `<p class="summary">No daily count data yet.</p>`;
}

function shiftOverlapsWindow(shift, windowText) {
  if (!windowText || windowText === "None") return false;
  const shiftRange = rangeToNumbers(shift.time || shift.shift_time);
  const mealRange = rangeToNumbers(windowText);
  if (!shiftRange || !mealRange) return false;
  return rangesOverlap(shiftRange.start, shiftRange.end, mealRange.start, mealRange.end);
}

function rangeToNumbers(rangeText) {
  const [startText, endText] = String(rangeText || "").split(/\s*-\s*/);
  const start = hourToNumber(startText);
  const end = hourToNumber(endText);
  if (start === null || end === null) return null;
  return { start, end };
}

function rangesOverlap(startA, endA, startB, endB) {
  const rangeA = normalizeRange(startA, endA);
  const rangeB = normalizeRange(startB, endB);
  return rangeA.some(a => rangeB.some(b => a.start < b.end && b.start < a.end));
}

function normalizeRange(start, end) {
  if (end <= start) {
    return [{ start, end: 24 }, { start: 0, end }];
  }
  return [{ start, end }];
}

async function assignVolunteerToManagedShift(forceOverride) {
  const shift = selectedManagedShift();
  const userId = $("#manageVolunteerSelect")?.value;
  if (!shift || !userId) return;
  await assignVolunteer(userId, shift.id, forceOverride);
}

async function revokeVolunteerFromManagedShift(skipConfirm) {
  const shift = selectedManagedShift();
  const userId = $("#manageVolunteerSelect")?.value;
  if (!shift || !userId) return;
  await revokeVolunteer(userId, shift.id, skipConfirm);
}

async function assignVolunteer(userId, shiftId, forceOverride = false) {
  const shift = shifts.find(item => String(item.id) === String(shiftId));
  const user = users.find(item => String(item.id) === String(userId));
  if (!shift || !user) return;

  const assigned = users.filter(item => userHasShift(item, shiftId));
  let override = forceOverride;
  if (assigned.length >= Number(shift.capacity || 0) && !override) {
    override = confirm("This shift is already full. Override capacity and assign anyway?");
    if (!override) return;
  } else if (!confirm(`Assign ${user.name} to ${shift.title}?`)) {
    return;
  }

  try {
    const data = await apiRequest("assign_shift", { userId, shiftId, assignAction: "assign", override });
    applyState(data);
    if ($("#assignmentMessage")) $("#assignmentMessage").textContent = `${user.name} assigned to ${shift.title}.`;
    showDialog([`${user.name} assigned to ${shift.title}.`]);
  } catch(err) {
    if ($("#assignmentMessage")) $("#assignmentMessage").textContent = err.message;
  }
}

async function revokeVolunteer(userId, shiftId, skipConfirm = false) {
  const shift = shifts.find(item => String(item.id) === String(shiftId));
  const user = users.find(item => String(item.id) === String(userId));
  if (!shift || !user) return;
  if (!skipConfirm && !confirm(`Revoke ${user.name} from ${shift.title}?`)) return;

  try {
    const data = await apiRequest("assign_shift", { userId, shiftId, assignAction: "revoke" });
    applyState(data);
    if ($("#assignmentMessage")) $("#assignmentMessage").textContent = `${user.name} removed from ${shift.title}.`;
    showDialog([`${user.name} removed from ${shift.title}.`]);
  } catch(err) {
    if ($("#assignmentMessage")) $("#assignmentMessage").textContent = err.message;
  }
}

async function deleteManagedShift() {
  const shift = selectedManagedShift();
  if (!shift) return;

  const assignedCount = users.filter(user => userHasShift(user, shift.id)).length;
  const detail = assignedCount
    ? ` This will also remove ${assignedCount} assignment${assignedCount === 1 ? "" : "s"} from this shift.`
    : "";

  if (!confirm(`Delete ${shift.title}?${detail}`)) {
    return;
  }

  try {
    const data = await apiRequest("delete_shift", { shiftId: shift.id });
    focusedManagedShiftId = null;
    applyState(data);
    if ($("#assignmentMessage")) $("#assignmentMessage").textContent = `${shift.title} deleted.`;
    showDialog([`${shift.title} deleted.`]);
  } catch(err) {
    if ($("#assignmentMessage")) $("#assignmentMessage").textContent = err.message;
  }
}

function updateExportControls() {
  const mode = $("#exportMode")?.value || "day";
  const dayLabel = $("#exportDay")?.closest("label");
  if (dayLabel) dayLabel.style.display = mode === "day" ? "grid" : "grid";
}

function exportScheduleCsv() {
  const mode = $("#exportMode")?.value || "day";
  const day = $("#exportDay")?.value || "All days";
  const rows = mode === "volunteer"
    ? exportRowsByVolunteer(day)
    : mode === "department"
      ? exportRowsByDepartment(day)
    : mode === "coordinator"
      ? exportRowsCoordinatorRollup(day)
      : exportRowsByDay(day);
  const fileName = mode === "volunteer"
    ? "delta-h-shifts-by-volunteer.csv"
    : mode === "department"
      ? "delta-h-shifts-by-department.csv"
    : mode === "coordinator"
      ? "delta-h-coordinator-rollup.csv"
      : "delta-h-shifts-by-day.csv";
  downloadCsv(fileName, rows);
  if ($("#exportMessage")) $("#exportMessage").textContent = "Export downloaded.";
  showDialog(["Export downloaded."]);
}

function exportAllergiesCsv() {
  const rows = exportRowsAllergies();
  if (rows.length === 1) {
    showDialog(["No allergy or dietary notes found for the current roster."]);
    return;
  }
  downloadCsv("delta-h-allergies.csv", rows);
  if ($("#exportMessage")) $("#exportMessage").textContent = "Allergy export downloaded.";
  showDialog(["Allergy export downloaded."]);
}

function exportRowsAllergies() {
  const header = ["Volunteer", "Email", "Department", "Allergies / Dietary Notes", "Working Days", "Assigned Shifts"];
  const rows = managerVisibleUsers()
    .filter(user => user.status === "approved" || user.status === "pending")
    .filter(user => allergyText(user) !== "")
    .map(user => {
      const assigned = managerVisibleShifts().filter(shift => userHasShift(user, shift.id));
      const days = Array.from(new Set(assigned.map(shift => shift.day || shift.shift_day))).join("; ");
      return [
        user.name,
        user.email,
        userDepartment(user),
        allergyText(user),
        days,
        assigned.map(shift => `${shift.day || shift.shift_day} ${shift.time || shift.shift_time} - ${shift.title}`).join("; ")
      ];
    });
  return [header, ...rows];
}

function exportRowsByDay(dayFilter) {
  const header = ["Day", "Time", "Department", "Shift", "Capacity", "Assigned", "Open Spots", "Food Count"];
  const rows = managerVisibleShifts()
    .filter(shift => dayFilter === "All days" || (shift.day || shift.shift_day) === dayFilter)
    .sort((a, b) => `${a.day || a.shift_day} ${a.time || a.shift_time}`.localeCompare(`${b.day || b.shift_day} ${b.time || b.shift_time}`))
    .map(shift => {
      const assigned = managerVisibleUsers().filter(user => userHasShift(user, shift.id));
      return [
        shift.day || shift.shift_day,
        shift.time || shift.shift_time,
        shift.department,
        shift.title,
        shift.capacity,
        assigned.map(user => user.name).join("; "),
        Math.max(Number(shift.capacity || 0) - assigned.length, 0),
        assigned.length
      ];
    });
  return [header, ...rows];
}

function exportRowsByVolunteer(dayFilter) {
  const header = ["Volunteer", "Email", "Department", "Day", "Time", "Shift", "Hours", "Food Entitled", "Buddy Request", "Carpool Request"];
  const rows = approvedVolunteers().flatMap(user => {
    const assigned = managerVisibleShifts().filter(shift => userHasShift(user, shift.id))
      .filter(shift => dayFilter === "All days" || (shift.day || shift.shift_day) === dayFilter);
    return assigned.map(shift => [
      user.name,
      user.email,
      userDepartment(user),
      shift.day || shift.shift_day,
      shift.time || shift.shift_time,
      shift.title,
      shift.hours,
      "Yes",
      user.buddyRequest || user.buddy_request || user.friend || "",
      user.carpoolRequest || user.carpool_request || ""
    ]);
  });
  return [header, ...rows];
}

function exportRowsByDepartment(dayFilter) {
  const header = ["Department", "Day", "Time", "Shift", "Capacity", "Assigned Count", "Assigned Volunteers", "Open Spots"];
  const rows = sortShifts(managerVisibleShifts(), "department")
    .filter(shift => dayFilter === "All days" || (shift.day || shift.shift_day) === dayFilter)
    .map(shift => {
      const assigned = managerVisibleUsers().filter(user => userHasShift(user, shift.id));
      return [
        shift.department,
        shift.day || shift.shift_day,
        shift.time || shift.shift_time,
        shift.title,
        shift.capacity,
        assigned.length,
        assigned.map(user => user.name).join("; "),
        Math.max(Number(shift.capacity || 0) - assigned.length, 0)
      ];
    });
  return [header, ...rows];
}

function exportRowsCoordinatorRollup(dayFilter) {
  const countHeader = ["Section", "Day", "Department", "Volunteer Count", "Food Count", "Breakfast Count", "Lunch Count", "Dinner Count", "Shift Count", "Volunteer", "Email", "Time", "Shift", "Hours", "Buddy Request", "Carpool Request"];
  const departmentLabel = isFullAdmin(currentUser) ? "All departments" : (currentUser.department || "My department");
  const countRows = dailyCoverageCounts(dayFilter).map(item => [
    "Daily count",
    item.day,
    departmentLabel,
    item.volunteerCount,
    item.foodCount,
    item.breakfastCount,
    item.lunchCount,
    item.dinnerCount,
    item.shiftCount,
    "",
    "",
    "",
    "",
    "",
    "",
    ""
  ]);
  const detailRows = approvedVolunteers().flatMap(user => {
    const assigned = managerVisibleShifts()
      .filter(shift => userHasShift(user, shift.id))
      .filter(shift => dayFilter === "All days" || (shift.day || shift.shift_day) === dayFilter);
    return assigned.map(shift => [
      "Volunteer schedule",
      shift.day || shift.shift_day,
      shift.department,
      "",
      "Yes",
      shiftOverlapsWindow(shift, mealWindows.breakfast) ? "Yes" : "",
      shiftOverlapsWindow(shift, mealWindows.lunch) ? "Yes" : "",
      shiftOverlapsWindow(shift, mealWindows.dinner) ? "Yes" : "",
      "",
      user.name,
      user.email,
      shift.time || shift.shift_time,
      shift.title,
      shift.hours,
      user.buddyRequest || user.buddy_request || user.friend || "",
      user.carpoolRequest || user.carpool_request || ""
    ]);
  });
  return [countHeader, ...countRows, ...detailRows];
}

function downloadShiftTemplate() {
  if (!window.XLSX) {
    const link = document.createElement("a");
    link.href = "delta-h-shift-import-template.xlsx";
    link.download = "delta-h-shift-import-template.xlsx";
    link.click();
    return;
  }

  const rows = [
    ["Department", "Title", "Day", "Time", "Hours", "Capacity", "Note"],
    ["Con Ops", "Morning coverage", "Friday", "8:00 AM - 12:00 PM", 4, 3, "Morning operations"],
    ["Con Suite", "Suite coverage", "Friday", "12:00 PM - 4:00 PM", 4, 2, "Con suite support"],
    ["Registration/Info Desk", "Lunch desk coverage", "Saturday", "11:00 AM - 3:00 PM", 4, 2, "Guest-facing desk"]
  ];
  const worksheet = XLSX.utils.aoa_to_sheet(rows);
  worksheet["!cols"] = [
    { wch: 24 },
    { wch: 28 },
    { wch: 14 },
    { wch: 22 },
    { wch: 10 },
    { wch: 10 },
    { wch: 30 }
  ];
  const workbook = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(workbook, worksheet, "Shifts");
  XLSX.writeFile(workbook, "delta-h-shift-import-template.xlsx");
}

async function importShiftWorkbook() {
  const file = $("#shiftImportFile")?.files?.[0];
  const message = $("#shiftImportMessage");
  if (!file) {
    if (message) message.textContent = "Choose an Excel file first.";
    return;
  }
  if (!window.XLSX) {
    if (message) message.textContent = "Excel importer is still loading. Refresh and try again.";
    return;
  }

  try {
    const parsed = await parseShiftWorkbook(file);
    const rows = parsed.rows || [];
    const parseErrors = parsed.errors || [];
    if (!rows.length) {
      const details = parseErrors.length ? ` Invalid rows: ${parseErrors.slice(0, 5).join(" | ")}` : "";
      throw new Error(`No valid shift rows found in that workbook.${details}`);
    }
    const skipText = parseErrors.length ? ` and skip ${parseErrors.length} invalid row${parseErrors.length === 1 ? "" : "s"}` : "";
    if (!confirm(`Import ${rows.length} valid shift${rows.length === 1 ? "" : "s"}${skipText} from this Excel file?`)) return;
    const data = await apiRequest("import_shifts", { shifts: rows });
    applyState(data);
    const imported = data.importedCount || 0;
    const allErrors = [...parseErrors, ...(data.importErrors || [])];
    const summary = `${imported} shift${imported === 1 ? "" : "s"} imported.${allErrors.length ? ` ${allErrors.length} row${allErrors.length === 1 ? "" : "s"} skipped.` : ""}`;
    const shortDetails = allErrors.slice(0, 8);
    if (message) message.textContent = shortDetails.length ? `${summary} ${shortDetails.join(" | ")}${allErrors.length > shortDetails.length ? " | See popup for full skipped-row list." : ""}` : summary;
    showDialog([summary, ...allErrors]);
    if ($("#shiftImportFile")) $("#shiftImportFile").value = "";
  } catch(err) {
    if (message) message.textContent = err.message;
    showDialog([err.message]);
  }
}

function parseShiftWorkbook(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => {
      try {
        const workbook = XLSX.read(reader.result, { type: "array" });
        const worksheet = workbook.Sheets[workbook.SheetNames[0]];
        if (!worksheet) throw new Error("Workbook does not have a first sheet.");
        const rawRows = XLSX.utils.sheet_to_json(worksheet, { defval: "" });
        const rows = [];
        const errors = [];
        rawRows.forEach((row, index) => {
          try {
            const clean = normalizeImportedShift(row, index + 2);
            if (clean) rows.push(clean);
          } catch(err) {
            errors.push(`Row ${index + 2}: ${err.message}`);
          }
        });
        resolve({ rows, errors });
      } catch(err) {
        reject(err);
      }
    };
    reader.onerror = () => reject(new Error("Could not read that Excel file."));
    reader.readAsArrayBuffer(file);
  });
}

function normalizeImportedShift(row, rowNumber = 0) {
  const department = cellValue(row, ["Department", "Dept"]);
  const title = cellValue(row, ["Title", "Shift", "Shift Title"]);
  const day = normalizeDay(cellValue(row, ["Day", "Shift Day"]));
  const time = normalizeTimeRange(cellValue(row, ["Time", "Shift Time"]));
  const hours = Number(cellValue(row, ["Hours", "Length"]));
  const capacity = Number(cellValue(row, ["Capacity", "Spots", "Slots"]));
  const note = cellValue(row, ["Note", "Notes"]);

  if (!department && !title && !day && !time) return null;
  if (!DELTA_H_DEPTS.includes(department)) throw new Error(`Unknown department: ${department || "(blank)"}. Use one of: ${DELTA_H_DEPTS.join(", ")}`);
  if (!SCHEDULE_DAYS.includes(day)) throw new Error(`Unknown day for ${title || "a shift"}: ${day || "(blank)"}.`);
  if (!title) throw new Error("Every imported shift needs a title.");
  if (!time || !rangeToNumbers(time)) throw new Error(`Shift "${title}" needs a time like 8:00 AM - 12:00 PM.`);
  if (!Number.isFinite(hours) || hours <= 0) throw new Error(`Shift "${title}" needs hours greater than 0.`);
  if (!Number.isFinite(capacity) || capacity <= 0) throw new Error(`Shift "${title}" needs capacity greater than 0.`);

  return { department, title, day, time, hours, capacity, note, rowNumber };
}

function cellValue(row, names) {
  const entries = Object.entries(row);
  for (const name of names) {
    const match = entries.find(([key]) => key.trim().toLowerCase() === name.toLowerCase());
    if (match) return String(match[1] ?? "").trim();
  }
  return "";
}

function normalizeDay(day) {
  const found = SCHEDULE_DAYS.find(item => item.toLowerCase() === String(day || "").trim().toLowerCase());
  return found || String(day || "").trim();
}

function normalizeTimeRange(value) {
  return String(value || "")
    .replace(/\s+to\s+/i, " - ")
    .replace(/[\u2013\u2014]/g, "-")
    .replace(/\s*-\s*/g, " - ")
    .trim();
}

function downloadCsv(fileName, rows) {
  const csv = rows.map(row => row.map(csvCell).join(",")).join("\n");
  const blob = new Blob([csv], { type: "text/csv;charset=utf-8" });
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = fileName;
  link.click();
  URL.revokeObjectURL(url);
}

function csvCell(value) {
  return `"${String(value ?? "").replaceAll('"', '""')}"`;
}

function allergyText(user) {
  const value = String(user.allergies ?? user.allergy ?? "").trim();
  return ["", "none", "no", "n/a", "na"].includes(value.toLowerCase()) ? "" : value;
}

function defaultProfilePhoto(name = "") {
  const initials = String(name).trim().split(/\s+/).map(part => part[0]).join("").slice(0, 2).toUpperCase() || "VC";
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96" viewBox="0 0 96 96"><rect width="96" height="96" rx="16" fill="#202124"/><text x="50%" y="54%" text-anchor="middle" dominant-baseline="middle" fill="#fff" font-family="Arial, sans-serif" font-size="32" font-weight="800">${initials}</text></svg>`;
  return `data:image/svg+xml;base64,${btoa(svg)}`;
}

function rosterPersonHtml(user) {
  const photo = user.profile_photo || user.profilePhoto || defaultProfilePhoto(user.name);
  return `
    <div class="roster-person">
      <img class="roster-photo" src="${photo}" alt="">
      <div><strong>${escapeHtml(user.name)}</strong><br><small>${escapeHtml(user.email)}</small></div>
    </div>
  `;
}

function fileToDataUrl(file) {
  return new Promise((resolve, reject) => {
    if (!["image/png", "image/jpeg", "image/webp"].includes(file.type)) {
      reject(new Error("Use a PNG, JPG, or WebP image."));
      return;
    }
    if (file.size > 750000) {
      reject(new Error("Image is too large. Please choose one under 750 KB."));
      return;
    }
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result || ""));
    reader.onerror = () => reject(new Error("Could not read that image."));
    reader.readAsDataURL(file);
  });
}

// Unified update function handles status, rank, and department changes
window.updateUserField = async (userId, fieldName, newValue) => {
  const label = fieldName.replaceAll("_", " ");
  if (!confirm(`Confirm ${label} change?`)) {
    applyState({ user: currentUser, volunteers: users, shifts, hotelRooms });
    return;
  }

  try {
    const data = await apiRequest("update_user", { id: userId, [fieldName]: newValue });
    applyState(data);
    showDialog([`${label.charAt(0).toUpperCase() + label.slice(1)} updated.`]);
  } catch(err) {
    alert("Failed to update: " + err.message);
  }
};

window.updateShirtPickup = async (userId, pickedUp, button) => {
  if (button) button.disabled = true;
  try {
    const data = await apiRequest("update_shirt_pickup", { userId, pickedUp });
    applyState(data);
  } catch(err) {
    if (button) button.disabled = false;
    showDialog([`T-shirt pickup was not updated: ${err.message}`]);
  }
};

init();

function collectAvailability(scope) {
  const availability = emptyAvailability();
  $$(`[data-availability-scope="${scope}"]:checked`).forEach(input => {
    const day = input.dataset.availabilityDay;
    if (availability[day]) availability[day].push(input.value);
  });
  return availability;
}

function collectApplicationDays() {
  return $$("[data-application-day]:checked").map(input => input.value);
}

function emptyAvailability() {
  return AVAILABILITY_DAYS.reduce((availability, day) => {
    availability[day] = [];
    return availability;
  }, {});
}

function normalizeAvailability(availability) {
  const normalized = emptyAvailability();
  if (typeof availability === "string") {
    try { availability = JSON.parse(availability); } catch { availability = null; }
  }
  if (!availability || typeof availability !== "object") return normalized;

  AVAILABILITY_DAYS.forEach(day => {
    normalized[day] = Array.isArray(availability[day])
      ? availability[day].filter(hour => AVAILABILITY_HOURS.includes(hour))
      : [];
  });
  return normalized;
}

function normalizeUser(user) {
  return {
    ...user,
    shiftIds: Array.isArray(user.shiftIds) ? user.shiftIds.map(String) : [],
    availability: normalizeAvailability(user.availability),
    clockedIn: Boolean(user.clockedIn ?? user.clocked_in),
    wedLoadout: Boolean(user.wedLoadout ?? user.wed_loadout),
    sunLoadout: Boolean(user.sunLoadout ?? user.sun_loadout),
    hotelNeeded: user.hotelNeeded ?? user.hotel_needed ?? "No",
    hotelRoom: user.hotelRoom ?? user.hotel_room ?? "",
    hotelCheckedIn: Boolean(user.hotelCheckedIn ?? user.hotel_checked_in),
    shirtSize: user.shirtSize ?? user.shirt_size ?? "",
    shirtPickedUp: Boolean(user.shirtPickedUp ?? user.shirt_picked_up),
    shirtPickedUpAt: user.shirtPickedUpAt ?? user.shirt_picked_up_at ?? "",
    shirtPickedUpBy: user.shirtPickedUpBy ?? user.shirt_picked_up_by ?? null,
    gender: user.gender || "",
    buddyRequest: user.buddyRequest ?? user.buddy_request ?? user.friend ?? "",
    carpoolRequest: user.carpoolRequest ?? user.carpool_request ?? "",
    dateOfBirth: user.dateOfBirth ?? user.date_of_birth ?? "",
    requestedHours: user.requestedHours ?? user.requested_hours ?? "",
    previousExperience: user.previousExperience ?? user.previous_experience ?? "",
    additionalNotes: user.additionalNotes ?? user.additional_notes ?? "",
    applicationSubmittedAt: user.applicationSubmittedAt ?? user.application_submitted_at ?? "",
    managementProfile: user.managementProfile || null,
    managementNotes: Array.isArray(user.managementNotes) ? user.managementNotes : [],
    canGuestRelations: Boolean(user.canGuestRelations),
    canSafety: Boolean(user.canSafety),
    canVendorHall: Boolean(user.canVendorHall)
  };
}

function availabilitySummary(availability) {
  const normalized = normalizeAvailability(availability);
  return AVAILABILITY_DAYS
    .map(day => `${day}: ${normalized[day].length ? normalized[day].join(", ") : "none"}`)
    .join(" | ");
}

function availabilityHasAny(availability) {
  const normalized = normalizeAvailability(availability);
  return AVAILABILITY_DAYS.some(day => normalized[day].length > 0);
}

function shiftMatchesAvailability(user, shift) {
  const day = shift.day || shift.shift_day;
  const availability = normalizeAvailability(user.availability);
  if (!availability[day]) return false;
  const coveredHours = shiftHourSlots(shift);
  return coveredHours.some(hour => availability[day].includes(hour));
}

function shiftHourSlots(shift) {
  const time = String(shift.time || shift.shift_time || "");
  const [startText, endText] = time.split(/\s*-\s*/);
  const start = hourToNumber(startText);
  const end = hourToNumber(endText);
  if (start === null || end === null) return [];
  return AVAILABILITY_HOURS.filter(hour => {
    const value = hourToNumber(hour);
    if (value === null) return false;
    if (end <= start) return value >= start || value < end;
    return value >= start && value < end;
  });
}

function hourToNumber(label) {
  const match = String(label || "").trim().match(/^(\d{1,2})(?::(\d{2}))?\s*(AM|PM)$/i);
  if (!match) return null;
  let hour = Number(match[1]);
  const minutes = Number(match[2] || 0);
  const period = match[3].toUpperCase();
  if (period === "AM" && hour === 12) hour = 0;
  if (period === "PM" && hour !== 12) hour += 12;
  return hour + (minutes / 60);
}

function updateClockStatus(lookup, clockedIn, messageSelector, adminMode) {
  apiRequest("clock", { lookup, clockedIn, adminMode })
    .then(data => {
      applyState(data);
      const message = messageSelector ? $(messageSelector) : null;
      if (message) message.textContent = `Volunteer clocked ${clockedIn ? "in" : "out"}.`;
    })
    .catch(err => {
      const message = messageSelector ? $(messageSelector) : null;
      if (message) message.textContent = err.message;
    });
}

function showDialog(messages) {
  if ($("#dialogContent") && $("#alertDialog")) {
    $("#dialogContent").innerHTML = `<ul>${messages.map(message => `<li>${escapeHtml(message)}</li>`).join("")}</ul>`;
    if ($("#alertDialog").showModal) $("#alertDialog").showModal();
    else alert(messages.join("\n"));
  } else {
    alert(messages.join("\n"));
  }
}

function escapeJs(value) {
  return String(value ?? "").replaceAll("\\", "\\\\").replaceAll("'", "\\'");
}

window.assignSpecificVolunteer = (userId, shiftId) => assignVolunteer(userId, shiftId, false);
window.revokeSpecificVolunteer = (userId, shiftId) => revokeVolunteer(userId, shiftId, false);
