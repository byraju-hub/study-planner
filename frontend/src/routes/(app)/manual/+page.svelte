<script>
	const sections = [
		{ id: 'start', title: '처음 시작하기' },
		{ id: 'subjects', title: '과목·교재·단원 만들기' },
		{ id: 'study', title: '일반 스터디' },
		{ id: 'timetable', title: '시간표 (나이스에서 가져오기)' },
		{ id: 'exam', title: '시험 대비' },
		{ id: 'assess', title: '수행평가' },
		{ id: 'quiz', title: '퀴즈와 문제 만들기' },
		{ id: 'notify', title: '알림' },
		{ id: 'account', title: '내 정보·계정' },
		{ id: 'tips', title: '알아 두면 좋은 것' }
	];
</script>

<svelte:head><title>사용 매뉴얼 · 스터디 플래너</title></svelte:head>

<div class="stack manual" style="gap: 20px; max-width: 760px;">
	<h1>사용 매뉴얼</h1>
	<p class="muted">스터디 플래너를 처음 쓰는 방법을 순서대로 정리했어요. 필요한 부분만 골라 읽어도 돼요.</p>

	<nav class="card toc" aria-label="매뉴얼 목차">
		<strong>목차</strong>
		<ol>
			{#each sections as s (s.id)}<li><a href="#{s.id}">{s.title}</a></li>{/each}
		</ol>
	</nav>

	<section id="start" class="card stack">
		<h2>처음 시작하기</h2>
		<ol>
			<li><strong>과목·교재</strong>에서 과목을 확인해요. 가입하면 고등학교 1학년 공통 과목 8개가 자동으로 만들어져요. 과목은 추가·수정·삭제할 수 있어요.</li>
			<li>과목에 <strong>교재</strong>(교과서, 참고서, 문제집, 학원 교재)를 넣고, 교재마다 <strong>단원(목차)</strong>을 만들어 두면 시험 범위와 퀴즈를 쉽게 고를 수 있어요.</li>
			<li>매일은 <strong>스터디</strong>에서 오늘 할 일을 적고 체크해요. 시험이나 수행평가가 생기면 <strong>시험 대비</strong>·<strong>수행평가</strong>에 등록해요. 그러면 해야 할 일이 오늘 할 일로 자동으로 들어와요.</li>
			<li>공부한 내용으로 <strong>퀴즈</strong>를 풀면 약한 단원을 알 수 있어요.</li>
		</ol>
		<p class="hint">홈에서는 마감이 가까운 수행평가와 내 과목을 한눈에 볼 수 있어요. 왼쪽 메뉴(휴대폰은 아래쪽 메뉴)로 어디서든 이동해요.</p>
	</section>

	<section id="subjects" class="card stack">
		<h2>과목·교재·단원 만들기</h2>
		<h3>과목</h3>
		<p><strong>과목·교재</strong> 화면 맨 위에서 이름과 색을 정하고 <strong>추가</strong>를 눌러요. 목록의 <strong>수정</strong>, <strong>삭제</strong>로 바꾸거나 지울 수 있어요. 과목을 지우면 그 과목의 교재·단원·자료도 함께 지워져요.</p>

		<h3>교재 추가 — 서점(YES24)에서 찾기</h3>
		<ol>
			<li>과목 이름 또는 <strong>교재 N · 추가</strong> 버튼을 눌러 과목 화면으로 들어가요.</li>
			<li><strong>+ 교재</strong>를 누르면 맨 위에 서점 검색칸이 나와요. 책 이름(예: <em>쎈 수학</em>)을 넣고 <strong>찾기</strong>를 눌러요.</li>
			<li>결과에서 내 책을 누르면 <strong>교재 이름·출판사·전체 쪽수</strong>가 자동으로 채워져요. 틀린 곳을 고치고 <strong>교재 추가</strong>를 눌러요.</li>
			<li>찾는 책이 없으면 검색 없이 이름만 직접 입력해도 돼요. 종류는 교과서, 참고서·자습서, 문제집, 학원 교재, 기타 중에서 골라요.</li>
		</ol>
		<p class="hint">서점 검색칸이 보이지 않으면 서점 연동이 아직 꺼져 있는 거예요. 관리자에게 알려 주세요. 그동안은 직접 입력하면 돼요.</p>

		<h3>단원(목차) 만들기</h3>
		<ol>
			<li>교재를 눌러 선택하면 아래에 <strong>단원 (목차)</strong>가 나와요.</li>
			<li><strong>서점 목차 가져오기</strong>를 누르면 교재 이름으로 검색이 미리 채워져요. 책을 고르면 목차가 단원 후보로 나뉘어 나와요.</li>
			<li>필요 없는 줄은 체크를 끄고, 이름이나 쪽수를 고친 뒤 저장해요. 서점 목차는 책마다 모양이 달라서 <strong>저장 전에 꼭 확인</strong>하는 게 좋아요. 쪽수가 없는 책도 많아요.</li>
			<li>직접 만들려면 <strong>+ 대단원</strong>과 <strong>+ 중단원 추가</strong>를 쓰면 돼요. 순서는 위·아래 버튼으로 바꿔요.</li>
		</ol>
		<p class="hint">단원 이름과 쪽수만 저장돼요. 책 본문이나 문제는 저장하지 않아요.</p>

		<h3>학습 자료 붙이기</h3>
		<p>단원을 선택하면 <strong>학습 자료</strong>에 메모, 링크, 파일(PDF·JPG·PNG, 10MB 이하)을 붙일 수 있어요. 내가 올린 자료는 나만 볼 수 있어요.</p>
	</section>

	<section id="study" class="card stack">
		<h2>일반 스터디</h2>
		<p>평소에 매일 쓰는 화면이에요. <strong>스터디</strong>(또는 위쪽 모드 탭의 <strong>일반 스터디</strong>)를 눌러요.</p>
		<ul>
			<li><strong>오늘 할 일</strong>: 과목과 내용을 적고 예상 시간(분)을 넣어요. 끝나면 체크해요. 못 한 일은 <strong>내일로</strong> 버튼으로 미룰 수 있어요. 시험 대비·수행평가에서 만든 할 일도 여기에 같이 나와요.</li>
			<li><strong>공부 타이머</strong>: 과목을 고르고 <strong>시작</strong>·<strong>정지</strong>를 눌러 공부한 시간을 기록해요. 최근 7일 공부 시간이 그래프로 나와요.</li>
			<li><strong>오늘 수업 → 당일 복습</strong>: 시간표에 오늘 수업이 있으면 교시마다 배운 내용을 한 줄로 적고 복습을 마쳐요(예: 나머지정리). 시간표를 먼저 입력해야 나와요.</li>
			<li><strong>오늘 복습할 것</strong>: 처음 공부한 다음 날, 3일, 7일, 14일, 30일 뒤에 복습이 차례로 나와요. 복습을 마치면 <strong>완료</strong>, 어려웠으면 <strong>어려웠어요</strong>를 눌러 내일 다시 봐요.</li>
			<li><strong>하루 회고</strong>: 잘한 점과 아쉬운 점을 짧게 적고 <strong>회고 저장</strong>을 눌러요.</li>
		</ul>
	</section>

	<section id="timetable" class="card stack">
		<h2>시간표 (나이스에서 가져오기)</h2>
		<p>우리 학교 시간표는 교육청이 공개한 나이스 자료에서 가져올 수 있어요.</p>
		<ol>
			<li><strong>스터디</strong> 화면에서 <strong>시간표</strong>로 들어가요.</li>
			<li><strong>나이스(neis.go.kr - 교육부 산하기관)에서 시간표 가져오기</strong>를 눌러요.</li>
			<li><strong>학교 이름</strong>을 넣고 <strong>검색</strong>해서 우리 학교를 골라요. 이름이 같은 학교가 여러 곳이면 지역과 주소를 보고 골라요.</li>
			<li><strong>학년</strong>과 <strong>반</strong>을 넣고 <strong>불러오기</strong>를 눌러요. 이번 주 시간표가 미리 보기로 나와요.</li>
			<li>시간표 칸이 내 과목으로 채워져요(예: 공통국어1 → 공통국어). 내 과목에 없는 수업은 건너뛰었다고 알려 줘요. 선택 과목처럼 반마다 다른 수업은 직접 확인해서 고쳐요. <strong>확인한 뒤 아래에서 저장</strong>해야 반영돼요.</li>
		</ol>
		<p class="hint">나이스에 학급 시간표가 아직 올라와 있지 않으면 비어 있다고 나올 수 있어요. 그때는 요일·교시에 과목을 직접 넣으면 돼요. 로그인 정보는 쓰지 않아요. 시간표를 넣어 두면 스터디에서 오늘 수업이 나와요.</p>
	</section>

	<section id="exam" class="card stack">
		<h2>시험 대비</h2>
		<ol>
			<li><strong>시험 대비</strong>에서 <strong>+ 시험 추가</strong>를 눌러 시험을 만들어요. 종류(중간·기말·모의고사), 시험 이름, 시작일·종료일을 넣고 <strong>시험 만들기</strong>를 눌러요.</li>
			<li>시험 상세 화면에서 <strong>시험 과목</strong>을 추가해요. 과목, 시험일, 교시, 목표 점수나 목표 등급을 정해요.</li>
			<li>과목마다 <strong>시험 범위</strong>를 넣어요. <strong>교재에서 고르기</strong>로 단원을 고르거나 "문학 1~2단원, 40쪽"처럼 직접 써도 돼요. 범위를 공부하면 진도가 표시돼요.</li>
			<li><strong>대비 계획</strong>에서 <strong>미리보기</strong>를 확인해요. 오늘부터 시험 전날까지 1회독, 문제풀이, 총정리로 나뉘어 날마다 할 일이 만들어져요. 마음에 들면 <strong>이대로 확정</strong>을 눌러요. 확정하면 오늘 할 일에 들어가요(이미 끝낸 일은 그대로 남아요).</li>
			<li>시험이 끝나면 실제 점수와 등급, 잘한 점, 아쉬운 점을 기록해요. 모아서 <strong>성적 기록</strong> 탭에서 볼 수 있어요.</li>
		</ol>
		<p class="hint">퀴즈에서 자주 틀리는 단원(약한 단원)은 대비 계획에 더 많은 시간이 배정돼요.</p>
	</section>

	<section id="assess" class="card stack">
		<h2>수행평가</h2>
		<ol>
			<li><strong>수행평가</strong>에서 <strong>+ 수행평가 추가</strong>를 눌러요. 과목, 종류(발표, 보고서 등), 이름, 마감일, 배점, 반영 비율, 평가 기준을 적어요. 선생님이 알려 준 기준을 그대로 적어 두면 좋아요.</li>
			<li>종류에 맞는 <strong>진행 단계</strong>(주제 정하기, 자료 조사, 초안 등)와 목표일이 자동으로 만들어져요. 단계는 고치거나 추가·삭제할 수 있어요.</li>
			<li>단계에 목표일을 정하면 그날 <strong>오늘 할 일</strong>에 나타나요. 단계에서 체크해도, 오늘 할 일에서 체크해도 서로 같이 바뀌어요.</li>
			<li>다 끝내면 <strong>제출했어요</strong>를 누르고, 점수가 나오면 받은 점수를 적어요.</li>
		</ol>
		<p class="hint">마감이 시험 기간(시험 7일 전부터)과 겹치면 경고가 표시돼요. 미리 일정을 앞당겨 보세요. 마감이 가까운 수행평가는 홈에도 나와요.</p>
	</section>

	<section id="quiz" class="card stack">
		<h2>퀴즈와 문제 만들기</h2>
		<h3>문제 만들기</h3>
		<p><strong>퀴즈</strong> → <strong>문제 만들기</strong>에서 먼저 <strong>문제를 만들 범위</strong>(과목과 단원)를 골라요. 문제 형식은 객관식, O/X, 단답, 카드 중에서 선택해요.</p>
		<ul>
			<li><strong>AI로 문제 만들기</strong> (화면 맨 위): <strong>열기</strong>를 누르고, 처음이라면 안내를 읽은 뒤 <strong>동의하고 시작</strong>을 눌러요. 단원에 적어 둔 노트나, <strong>직접 붙여 넣은</strong> 내용으로 문제 후보를 만들어요. 후보는 틀린 내용이 있을 수 있으니 <strong>읽어 보고, 맞는 것만 골라</strong> <strong>선택한 문제 저장</strong>을 눌러요. 하루에 만들 수 있는 횟수가 정해져 있어요. AI에는 내가 쓴 노트만 보내져요.</li>
			<li><strong>새 문제 직접 만들기</strong>: 문제, 보기, 정답, 해설을 직접 적어요. 책이나 문제집 문제를 그대로 옮기기보다 내가 이해한 내용으로 만들어 보세요.</li>
		</ul>
		<p class="hint">AI 문제 만들기는 관리자가 켜 두어야 쓸 수 있어요.</p>

		<h3>퀴즈 풀기</h3>
		<ol>
			<li><strong>퀴즈</strong> 화면의 <strong>퀴즈 시작</strong>에서 과목·단원과 방식(틀린 것·안 푼 것 먼저 / 안 푼 문제만 / 오답노트만), 문제 수를 고르고 <strong>시작하기</strong>를 눌러요.</li>
			<li>답을 내면 채점되고 해설을 볼 수 있어요.</li>
			<li>틀린 문제는 <strong>오답노트</strong>에 모이고, 다시 풀어 맞히면 자동으로 사라져요.</li>
			<li>퀴즈 화면의 <strong>내 정답률</strong>과 <strong>약한 단원</strong>(3문제 이상 풀고 정답률 60% 미만)을 보고, <strong>이 단원 풀기</strong>로 바로 연습해요.</li>
		</ol>
	</section>

	<section id="notify" class="card stack">
		<h2>알림</h2>
		<ul>
			<li>화면 오른쪽 위의 <strong>종 아이콘</strong>이 알림함이에요. 안 읽은 알림은 숫자로 표시돼요.</li>
			<li>아침에는 오늘 할 일과 복습, 저녁에는 못 끝낸 일, 그리고 시험(D-7, D-3, D-1)과 수행평가(D-3, D-1, 당일) 알림이 와요.</li>
			<li>알림함의 <strong>알림 설정</strong>에서 종류별로 켜고 끄고, 아침·저녁 알림 시각을 바꿔요.</li>
			<li><strong>이 기기로 푸시 받기</strong>를 켜면 앱을 닫아 둬도 휴대폰에 알림이 와요. 아이폰은 사파리에서 <strong>홈 화면에 추가</strong>한 앱에서만 받을 수 있어요.</li>
		</ul>
	</section>

	<section id="account" class="card stack">
		<h2>내 정보·계정</h2>
		<ul>
			<li><strong>설정</strong>에서 이름, 학교, 학년, 반을 고치고 <strong>비밀번호</strong>를 바꿀 수 있어요.</li>
			<li><strong>로그아웃</strong>은 설정 화면에 있어요.</li>
			<li><strong>회원 탈퇴</strong>를 하면 과목·교재·자료·기록이 모두 지워지고 되돌릴 수 없어요.</li>
			<li>가입은 관리자가 승인한 뒤에 쓸 수 있을 수도 있어요. 승인 대기 중이면 관리자에게 알려 주세요.</li>
			<li>비밀번호를 잊어버렸다면 관리자에게 초기화를 요청하세요. 초기화되면 처음 로그인할 때 새 비밀번호를 정해야 해요.</li>
		</ul>
		<h3>관리자 메뉴 (관리자만 보여요)</h3>
		<p><strong>관리</strong>에서 가입 방식(자유 가입 / 승인제 / 닫기), 회원 승인·차단·비밀번호 초기화·삭제, AI 문제 만들기 사용 여부와 AI 모델 선택, 작업 기록을 볼 수 있어요. 관리자는 회원의 공부 내용이나 자료는 볼 수 없어요.</p>
	</section>

	<section id="tips" class="card stack">
		<h2>알아 두면 좋은 것</h2>
		<ul>
			<li>시험·수행평가 화면에서 만든 할 일은 모두 <strong>스터디의 오늘 할 일</strong>에 모여요. 매일은 그 화면만 열어도 돼요.</li>
			<li>서점이나 나이스에서 가져온 정보는 <strong>저장하기 전에 항상 내가 확인하고 고칠 수 있어요</strong>. 자동으로 저장되지 않아요.</li>
			<li>내 자료와 기록은 나만 볼 수 있어요. 다른 사람과 공유되지 않아요.</li>
			<li>책 정보 출처는 <a href="https://www.yes24.com" target="_blank" rel="noopener">YES24</a>, 시간표 출처는 교육부 나이스 교육정보 개방 포털이에요.</li>
			<li>오류가 나거나 이상한 점이 있으면 화면에 나온 문구를 그대로 관리자에게 알려 주세요.</li>
		</ul>
	</section>
</div>

<style>
	.manual h2 {
		font-size: 20px;
	}
	.manual h3 {
		font-size: 16px;
		margin-top: 8px;
	}
	.manual ol,
	.manual ul {
		margin: 0;
		padding-left: 22px;
		display: flex;
		flex-direction: column;
		gap: 8px;
		line-height: 1.6;
	}
	.manual p {
		margin: 0;
		line-height: 1.6;
	}
	.toc ol {
		margin: 6px 0 0;
		padding-left: 20px;
		display: grid;
		gap: 4px;
	}
	section {
		scroll-margin-top: 16px;
	}
</style>
